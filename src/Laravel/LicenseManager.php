<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel;

use DateTimeImmutable;
use Faveo\LicenseClient\Http\Network;
use Faveo\LicenseClient\Laravel\Events\GraceStarted;
use Faveo\LicenseClient\Laravel\Events\LicenseChecked;
use Faveo\LicenseClient\Laravel\Events\LicenseFailed;
use Faveo\LicenseClient\License;
use Faveo\LicenseClient\Status;
use Faveo\LicenseClient\Store\FileStore;
use Faveo\LicenseClient\Store\Store;
use Faveo\LicenseClient\Updater\Updater;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

/**
 * The main product's licence and its add-ons, with the status memoised for the current request
 * (bound as scoped, so Octane requests and queue jobs each start fresh).
 *
 * $addon is '' for the main product, or a key of config('license-client.addons').
 */
final class LicenseManager
{
    /** @var array<string, License> */
    private array $licenses = [];

    /** @var array<string, Status> */
    private array $statuses = [];

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
        private readonly Dispatcher $events,
        private readonly Translator $translator,
    ) {}

    /** Memoised status: at most one check per request. */
    public function status(string $addon = ''): Status
    {
        return $this->statuses[$addon] ??= $this->verify(false, $addon);
    }

    /** Status of an add-on licence: License::for('calendar')->allows('sync'). */
    public function for(string $addon): Status
    {
        return $this->status($addon);
    }

    public function verify(bool $force = false, string $addon = ''): Status
    {
        $status = $this->license($addon)->verify($force);
        $this->dispatch($addon, $status);

        return $this->statuses[$addon] = $status;
    }

    public function install(string $email, string $licenseCode, string $addon = '', ?string $rootUrl = null): Status
    {
        $status = $this->license($addon)->install($email, $licenseCode, $rootUrl);
        $this->dispatch($addon, $status);
        unset($this->statuses[$addon]);

        return $status;
    }

    /**
     * FILE mode: the code the customer pastes on billing's "Activate offline" page.
     *
     * @throws RuntimeException message is a translatable code (see License::activationCode)
     */
    public function activationCode(string $licenseCode, string $addon = '', ?string $rootUrl = null): string
    {
        return $this->license($addon)->activationCode($licenseCode, $rootUrl);
    }

    /** FILE mode: install an uploaded licence file (activation, renewal, new server). */
    public function import(string $contents, string $addon = '', ?string $rootUrl = null): Status
    {
        $status = $this->license($addon)->import($contents, $rootUrl);
        $this->dispatch($addon, $status);
        unset($this->statuses[$addon]);

        return $status;
    }

    /**
     * One-time move from the old apl_* licence scripts: activates each licence found in their tables
     * (the product in $table, add-ons in $pluginTable by PLUGIN_NAME) exactly like license:install.
     * Licences already installed here, and add-ons this app doesn't configure, are skipped. The old
     * tables are left alone.
     *
     * @return array<string, Status> by add-on ('' = main product)
     */
    public function importLegacy(string $table = 'faveo_license', string $pluginTable = 'plugin_license'): array
    {
        /** @var DatabaseManager $db */
        $db = $this->app->make('db');
        $db = $db->connection();
        $addons = array_keys((array) $this->config->get('license-client.addons', []));
        $rows = [];

        if ($db->getSchemaBuilder()->hasTable($table)) {
            $rows[''] = $db->table($table)->first();
        }

        if ($db->getSchemaBuilder()->hasTable($pluginTable)) {
            foreach ($db->table($pluginTable)->get() as $row) {
                foreach ($addons as $addon) {
                    if (strcasecmp((string) $addon, (string) ($row->PLUGIN_NAME ?? '')) === 0) {
                        $rows[(string) $addon] = $row;
                    }
                }
            }
        }

        $results = [];
        foreach (array_filter($rows) as $addon => $row) {
            // The old scripts kept these three in plain text; a table without them was never activated.
            $code = (string) ($row->LICENSE_CODE ?? '');

            if ($code !== '' && $this->license($addon)->licenseCode() === null) {
                $results[$addon] = $this->install((string) ($row->CLIENT_EMAIL ?? ''), $code, $addon, ((string) ($row->ROOT_URL ?? '')) ?: null);
            }
        }

        return $results;
    }

    public function uninstall(string $addon = ''): void
    {
        $this->license($addon)->uninstall();
        unset($this->statuses[$addon]);
    }

    public function ok(string $addon = ''): bool
    {
        return $this->status($addon)->ok();
    }

    public function allows(string $feature, string $addon = ''): bool
    {
        return $this->status($addon)->allows($feature);
    }

    public function limit(string $name, string $addon = ''): ?int
    {
        return $this->status($addon)->limit($name);
    }

    public function withinLimit(string $name, int $count, string $addon = ''): bool
    {
        return $this->status($addon)->withinLimit($name, $count);
    }

    public function expiresAt(string $name, string $addon = ''): ?DateTimeImmutable
    {
        return $this->status($addon)->expiresAt($name);
    }

    /** Human text for a status, from lang/<locale>/messages.php: by case, then by state, then billing's text. */
    public function message(Status $status): string
    {
        if ($status->errors) {
            return implode('; ', array_map($this->text(...), $status->errors));
        }
        foreach ([$status->case, $status->state->value] as $key) {
            if ($this->translator->has("license-client::messages.$key")) {
                return $this->text($key);
            }
        }

        return $status->serverText ?: $status->case;
    }

    public function text(string $key): string
    {
        $text = $this->translator->get("license-client::messages.$key");

        return is_string($text) && $text !== "license-client::messages.$key" ? $text : $key;
    }

    /** The underlying framework-free client. */
    public function license(string $addon = ''): License
    {
        return $this->licenses[$addon] ??= $this->make($addon);
    }

    private function make(string $addon): License
    {
        $main = $this->settings('');
        $over = $addon === '' ? [] : $this->settings($addon);
        $get = fn (string $key, mixed $default = null): mixed => $over[$key] ?? $main[$key] ?? $default;

        // CLI has no request to read the URL from: fall back to APP_URL there.
        $rootUrl = $get('root_url') ?? ($this->app->runningInConsole() ? $this->config->get('app.url') : null);

        return new License(
            serverUrl: (string) $get('server_url', ''),
            productId: (int) $get('product_id', 0),
            salt: (string) $get('salt', ''),
            store: $this->store($main),
            mode: strtoupper((string) $get('mode', License::FILE)),
            licenseFile: is_string($file = $get('license_file')) && $file !== '' ? IdentityFile::resolve($file, $this->app->basePath()) : null,
            publicKey: $get('public_key'),
            rootUrl: is_string($rootUrl) ? $rootUrl : null,
            days: (int) $get('days', 5),
            graceDays: (int) $get('grace_days', 7),
            retryMinutes: (int) $get('retry_minutes', 60),
            pluginName: $addon,
            machineScope: $addon === '' ? null : (string) ($main['product_id'] ?? ''),
            allowIpAccess: (bool) $get('allow_ip_access', false),
            version: $get('report_version'),
            verifySsl: (bool) $get('verify_ssl', true),
            rootIp: (string) $get('root_ip', ''),
            nameservers: array_values((array) $get('nameservers', [])),
            network: $this->network(),
        );
    }

    /**
     * Updater for the product ('') or an add-on. An add-on uses its own product_id, product_key,
     * install_dir, replace_dirs and (under <backup_dir>/addons/<product_id>) backups — never the main
     * product's replace_dirs, so a plugin update can't empty the product's vendor/. Every updater
     * shares one lock: a plugin and the product never update at the same time.
     *
     * $with sets add-on settings directly, for a plugin that isn't installed yet (from its update file).
     *
     * @param  array<string, mixed>  $with
     */
    public function updater(string $addon = '', array $with = []): Updater
    {
        $main = $this->settings('');
        $updates = (array) ($main['updates'] ?? []);
        $own = $addon === '' ? $updates : $with + (isset($with['product_id']) ? [] : $this->settings($addon));
        $get = fn (string $key, mixed $default = null): mixed => $own[$key] ?? $main[$key] ?? $updates[$key] ?? $default;
        $backupDir = (string) ($updates['backup_dir'] ?? storage_path('app/license-client/backups'));
        $productId = (int) ($addon === '' ? ($main['product_id'] ?? 0) : ($own['product_id'] ?? 0));

        return new Updater(
            serverUrl: (string) $get('server_url', ''),
            productId: $productId,
            productKey: (string) ($own['product_key'] ?? ($addon === '' ? '' : ($updates['product_key'] ?? ''))),
            installDir: (string) ($own['install_dir'] ?? $updates['install_dir'] ?? $this->app->basePath()),
            backupDir: $addon === '' ? $backupDir : "$backupDir/addons/$productId",
            publicKey: $get('public_key'),
            requireSignedArchives: (bool) $get('require_signed_archives', true),
            replaceDirs: (array) ($own['replace_dirs'] ?? []),
            maxArchiveMb: (int) $get('max_archive_mb', 1024),
            keepBackups: (int) $get('keep_backups', 2),
            timeout: (int) $get('timeout', 300),
            verifySsl: (bool) $get('verify_ssl', true),
            tempDir: $get('temp_dir'),
            network: $this->network(),
            lockFile: "$backupDir/.lock",
        );
    }

    /** The add-on whose product_id this is, from config or discovered identity files. */
    public function addonForProduct(int $productId): ?string
    {
        foreach ((array) $this->config->get('license-client.addons', []) as $name => $entry) {
            if (is_array($entry) && (int) ($entry['product_id'] ?? 0) === $productId) {
                return (string) $name;
            }
        }

        return null;
    }

    /** Installed version of the product ('') or an add-on, if known. */
    public function installedVersion(string $addon = ''): ?string
    {
        $version = $addon === ''
            ? $this->config->get('license-client.updates.current_version')
            : ((array) $this->config->get("license-client.addons.$addon", []))['version'] ?? null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * The main settings for '', else the add-on's own entry (configured or discovered).
     *
     * @return array<string, mixed>
     */
    private function settings(string $addon): array
    {
        if ($addon === '') {
            /** @var array<string, mixed> */
            return (array) $this->config->get('license-client', []);
        }
        $over = ((array) $this->config->get('license-client.addons', []))[$addon] ?? null;

        if (! is_array($over)) {
            throw new InvalidArgumentException("Unknown licence add-on [$addon]; add it to license-client.addons, or install the plugin so its identity file can be discovered.");
        }

        /** @var array<string, mixed> $over */
        return $over;
    }

    /** Tests (and only tests) bind a fake billing server here. */
    private function network(): ?Network
    {
        return $this->app->bound(Network::class) ? $this->app->make(Network::class) : null;
    }

    /** @param  array<string, mixed>  $main */
    private function store(array $main): Store
    {
        /** @var array{driver?: string, connection?: string|null, table?: string, path?: string} $cfg */
        $cfg = (array) ($main['store'] ?? []);

        if (($cfg['driver'] ?? 'database') === 'file') {
            return new FileStore((string) ($cfg['path'] ?? storage_path('app/license-client/cache.json')));
        }

        /** @var DatabaseManager $db */
        $db = $this->app->make('db');

        return new DatabaseStore($db->connection($cfg['connection'] ?? null), $cfg['table'] ?? 'license_cache');
    }

    private function dispatch(string $addon, Status $status): void
    {
        if ($status->cached) {
            return;
        }
        $this->events->dispatch(new LicenseChecked($addon, $status));

        if ($status->graceStarted) {
            $this->events->dispatch(new GraceStarted($addon, $status));
        }

        if (! $status->ok()) {
            $this->events->dispatch(new LicenseFailed($addon, $status));
        }
    }
}
