<?php

declare(strict_types=1);

namespace Faveo\LicenseClient;

use Closure;
use DateTimeImmutable;
use Faveo\LicenseClient\Http\CurlNetwork;
use Faveo\LicenseClient\Http\Network;
use Faveo\LicenseClient\Store\Store;
use RuntimeException;

/**
 * Licence client for Faveo billing (Auto PHP Licenser compatible API).
 *
 *   FILE mode     - offline: trusts a signed license.json (Ed25519), bound to domain + machine.
 *   DATABASE mode - online: asks billing every $days days; survives outages for $graceDays.
 *
 * Either way a small sealed cache row lives in a Store so most verify() calls do no I/O beyond it.
 */
final class License
{
    public const FILE = 'FILE';

    public const DATABASE = 'DATABASE';

    private const DAY = 86400;

    private readonly Network $network;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param  list<string>  $nameservers
     * @param  (Closure(): int)|null  $clock  unix time source (tests)
     */
    public function __construct(
        private readonly string $serverUrl,          // billing root, e.g. https://billing.example.com
        private readonly int $productId,
        private readonly string $salt,               // random secret per app; never change after install
        private readonly Store $store,
        private readonly string $mode = self::FILE,
        private readonly ?string $licenseFile = null,
        private readonly ?string $publicKey = null,  // base64 Ed25519; FILE mode + signed entitlements
        private readonly ?string $rootUrl = null,    // this app's URL; detected from the request when null
        private readonly int $days = 5,              // days between full checks (1-365)
        private readonly int $graceDays = 7,         // DATABASE: days to keep working while billing is unreachable; 0 = off
        private readonly int $retryMinutes = 60,     // DATABASE: how often to retry billing while in grace / refused
        private readonly string $pluginName = '',    // add-ons: the add-on's name; '' = main product
        private readonly ?string $machineScope = null, // machine id scope; add-ons pass the main product id
        private readonly bool $allowIpAccess = false,
        private readonly ?string $version = null,    // DATABASE: installed version reported to billing
        private readonly bool $verifySsl = true,
        private readonly string $rootIp = '',        // optional: pin billing's IP
        private readonly array $nameservers = [],    // optional: pin billing's nameservers
        ?Network $network = null,
        ?Closure $clock = null,
    ) {
        $this->network = $network ?? new CurlNetwork;
        $this->clock = $clock ?? time(...);
    }

    public function name(): string
    {
        return $this->pluginName;
    }

    /** Activate the licence on this installation. */
    public function install(string $email, string $licenseCode, ?string $rootUrl = null): Status
    {
        if ($errors = $this->configErrors()) {
            return $this->misconfigured($errors);
        }

        // A tampered or corrupted row is replaced, so "install the license again" always works.
        if (($existing = $this->store->get($this->pluginName)) !== null && $this->rowIsValid($existing)) {
            return $this->status(State::Invalid, 'notification_already_installed');
        }

        $rootUrl = rtrim($rootUrl ?? $this->appUrl() ?? '', '/');

        if ($errors = $this->inputErrors($rootUrl, $email, $licenseCode)) {
            return $this->status(State::Invalid, 'notification_user_input_invalid', errors: $errors);
        }
        $row = ['root_url' => $rootUrl, 'client_email' => $email, 'license_code' => $licenseCode];

        if ($this->mode === self::FILE) {
            $license = $this->verifiedFile($rootUrl, $licenseCode);

            return $license instanceof Status ? $license : $this->saveActive($row, $this->fileEntitlements($license), $license);
        }

        $reply = $this->callServer('/api/licenseInstall', $row, $this->pluginName !== '' ? ['isPlugin' => $this->pluginName] : []);

        if (! $reply['reached']) {
            return $this->status(State::Unreachable, $reply['case']);
        }
        $state = self::stateFor($reply['case']);

        if ($state !== State::Active) {
            return $this->status($state, $reply['case'], serverText: $reply['text'], data: $reply['data']);
        }
        $entitlements = $this->serverEntitlements($reply['data'], $row);

        return $entitlements === null
            ? $this->status(State::Unreachable, 'notification_invalid_response')
            : $this->saveActive($row, $entitlements, $reply['data']);
    }

    /** Check the licence. Cheap on most calls: a full check only every $days days, or when $force. */
    public function verify(bool $force = false): Status
    {
        if ($errors = $this->configErrors()) {
            return $this->misconfigured($errors);
        }

        $row = $this->store->get($this->pluginName);

        if ($row === null) {
            // FILE mode is self-sufficient: a missing row is rebuilt from the signed file.
            return $this->mode === self::FILE ? $this->fileCheck(null) : $this->status(State::NotInstalled, 'notification_license_corrupted');
        }

        if (! $this->rowIsValid($row)) {
            return $this->status(State::Tampered, 'notification_license_corrupted');
        }

        /** @var array{root_url: string, client_email: string, license_code: string, checked_at: int, grace_since: int|null, retry_at: int|null, refusal: string|null, entitlements: array<mixed>} $row */
        $now = $this->now();

        if (! $force && $row['retry_at'] !== null && $now < $row['retry_at']) {
            if ($row['refusal'] !== null) {
                return $this->status(self::stateFor($row['refusal']), $row['refusal'], checkedAt: $row['checked_at'], cached: true);
            }
            $grace = $this->graceStatus($row, cached: true);

            if ($grace->state === State::Grace) {
                return $grace; // don't hit an unreachable billing on every request
            }
        }

        if (! $force && $row['retry_at'] === null && $now - $row['checked_at'] < $this->days * self::DAY) {
            return $this->status(State::Active, 'notification_license_ok', Entitlements::fromCache($row['entitlements']), $row['checked_at'], cached: true);
        }

        return $this->mode === self::FILE ? $this->fileCheck($row) : $this->serverCheck($row);
    }

    /**
     * FILE mode activation code: everything billing needs to bind this installation and sign its
     * licence file — "FLC1." + base64url(JSON {v, product_id, license_code, domain, machine_id, addon}).
     * The customer pastes it on billing's "Activate offline" page; nothing is sent from here.
     *
     * @throws RuntimeException message is a stable code: input_invalid_url, input_empty_license_data,
     *                          config_invalid_product_id or notification_machine_id_unavailable
     */
    public function activationCode(string $licenseCode, ?string $rootUrl = null): string
    {
        $rootUrl = rtrim($rootUrl ?? $this->appUrl() ?? '', '/');
        $error = match (true) {
            $this->productId < 1 => 'config_invalid_product_id',
            ! self::isValidUrl($rootUrl) => 'input_invalid_url',
            trim($licenseCode) === '' => 'input_empty_license_data',
            default => null,
        };

        if ($error !== null) {
            throw new RuntimeException($error);
        }

        $payload = (string) json_encode([
            'v' => 1,
            'product_id' => $this->productId,
            'license_code' => trim($licenseCode),
            'domain' => self::host($rootUrl),
            'machine_id' => MachineId::hashedId($this->machineScope ?? (string) $this->productId), // throws when unavailable
            'addon' => $this->pluginName !== '' ? $this->pluginName : null,
        ], JSON_UNESCAPED_SLASHES);

        return 'FLC1.'.rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /**
     * FILE mode: install a licence file uploaded by the customer (first activation, renewal, or a
     * move to a new server). The file is fully checked — billing's signature, product, domain,
     * machine, status, expiry — BEFORE it replaces the current one, so a wrong upload never breaks
     * a working licence.
     */
    public function import(string $contents, ?string $rootUrl = null): Status
    {
        if ($this->mode !== self::FILE) {
            return $this->status(State::Invalid, 'notification_not_file_mode');
        }

        if ($errors = $this->configErrors()) {
            return $this->misconfigured($errors);
        }

        $rootUrl = rtrim($rootUrl ?? $this->appUrl() ?? '', '/');

        if (! self::isValidUrl($rootUrl)) {
            return $this->status(State::Invalid, 'notification_user_input_invalid', errors: ['input_invalid_url']);
        }
        $license = $this->verifiedLicense($contents, $rootUrl, null);

        if ($license instanceof Status) {
            return $license;
        }

        if (! $this->writeLicenseFile($contents)) {
            return $this->status(State::Misconfigured, 'notification_write_error', errors: ['notification_write_error']);
        }
        $email = $this->store->get($this->pluginName)['client_email'] ?? '';

        return $this->saveActive([
            'root_url' => $rootUrl,
            'client_email' => is_string($email) ? $email : '',
            'license_code' => (string) $license['license_code'],
        ], $this->fileEntitlements($license), $license);
    }

    /** The installed licence code, or null when not installed / tampered. Sent with update downloads. */
    public function licenseCode(): ?string
    {
        $row = $this->store->get($this->pluginName);

        return $row !== null && $this->rowIsValid($row) ? (string) $row['license_code'] : null;
    }

    /** Remove the local cache row (a licence file, if any, is left alone). */
    public function uninstall(): void
    {
        $this->store->delete($this->pluginName);
    }

    /** Can billing be reached and does it answer like billing? */
    public function checkConnection(): bool
    {
        $res = $this->network->post($this->serverUrl().'/api/ConnectionTest', [
            'product_id' => $this->productId,
            'connection_hash' => hash('sha256', 'connection_test'),
        ], 10, $this->verifySsl);

        return ! $res->failed() && $res->body === '<connection_test>OK</connection_test>';
    }

    /** @return list<string> config_* codes, empty when fine */
    public function configErrors(): array
    {
        $errors = [];

        if (strlen($this->salt) < 16 || $this->salt === 'some_random_text') {
            $errors[] = 'config_invalid_salt';
        }

        if (! self::isValidUrl($this->serverUrl())) {
            $errors[] = 'config_invalid_server_url';
        }

        if ($this->productId < 1) {
            $errors[] = 'config_invalid_product_id';
        }

        if ($this->days < 1 || $this->days > 365) {
            $errors[] = 'config_invalid_days';
        }

        if ($this->graceDays < 0 || $this->retryMinutes < 1) {
            $errors[] = 'config_invalid_grace';
        }

        if ($this->mode !== self::FILE && $this->mode !== self::DATABASE) {
            $errors[] = 'config_invalid_mode';
        }

        if ($this->mode === self::FILE && ($this->licenseFile === null || $this->licenseFile === '')) {
            $errors[] = 'config_missing_license_file';
        }

        if (($this->mode === self::FILE || $this->publicKey) && ! self::validKey($this->publicKey)) {
            $errors[] = 'config_invalid_public_key';
        }

        // Offline (FILE) never touches the network, not even DNS: these pins only guard billing calls.
        if ($this->mode === self::DATABASE && $this->rootIp !== '' && ! in_array($this->rootIp, $this->serverIps(), true)) {
            $errors[] = 'config_ip_mismatch';
        }

        if ($this->mode === self::DATABASE && $this->nameservers) {
            $actual = array_map(fn (array $r): string => strtolower((string) $r['target']), @dns_get_record(self::host($this->serverUrl()), DNS_NS) ?: []);
            $expected = array_map(strtolower(...), $this->nameservers);
            sort($actual);
            sort($expected);

            if ($actual !== $expected) {
                $errors[] = 'config_nameserver_mismatch';
            }
        }

        return $errors;
    }

    /* ------------------------------------------------------------------ checks */

    /** @param  array<string, mixed>|null  $row */
    private function fileCheck(?array $row): Status
    {
        $url = rtrim($this->appUrl() ?? (string) ($row['root_url'] ?? ''), '/');
        $license = $this->verifiedFile($url, null);

        if ($license instanceof Status) {
            return $license;
        }

        // The signed file is authoritative; the cached licence code follows it.
        return $this->saveActive([
            'root_url' => (string) ($row['root_url'] ?? $url),
            'client_email' => (string) ($row['client_email'] ?? ''),
            'license_code' => (string) $license['license_code'],
        ], $this->fileEntitlements($license), $license);
    }

    /** @param  array{root_url: string, client_email: string, license_code: string, checked_at: int, grace_since: int|null, retry_at: int|null, refusal: string|null, entitlements: array<mixed>}  $row */
    private function serverCheck(array $row): Status
    {
        $reply = $this->callServer('/api/licenseVerify', $row);
        $entitlements = $reply['reached'] ? $this->serverEntitlements($reply['data'], $row) : null;
        $state = self::stateFor($reply['case']);

        if (! $reply['reached'] || ($state === State::Active && $entitlements === null)) {
            return $this->enterGrace($row);
        }

        if ($state !== State::Active) {
            // A definite answer from billing: never covered by grace. Cached so we don't ask on every request.
            $saved = $this->save(['refusal' => $reply['case'], 'retry_at' => $this->now() + $this->retryMinutes * 60, 'grace_since' => null] + $row);

            return $saved ?? $this->status($state, $reply['case'], checkedAt: $row['checked_at'], serverText: $reply['text'], data: $reply['data']);
        }

        $this->reportVersion($row);

        return $this->saveActive($row, $entitlements, $reply['data']);
    }

    /** @param  array{root_url: string, client_email: string, license_code: string, checked_at: int, grace_since: int|null, retry_at: int|null, refusal: string|null, entitlements: array<mixed>}  $row */
    private function enterGrace(array $row): Status
    {
        $started = $row['grace_since'] === null;
        $row = ['grace_since' => $row['grace_since'] ?? $this->now(), 'retry_at' => $this->now() + $this->retryMinutes * 60, 'refusal' => null] + $row;

        if ($failed = $this->save($row)) {
            return $failed;
        }
        $status = $this->graceStatus($row, cached: false);

        return $started && $status->state === State::Grace
            ? $this->status(State::Grace, $status->case, $status->entitlements, $row['checked_at'], $status->graceEndsAt?->getTimestamp(), graceStarted: true)
            : $status;
    }

    /** @param  array{checked_at: int, grace_since: int|null, entitlements: array<mixed>}  $row */
    private function graceStatus(array $row, bool $cached): Status
    {
        $endsAt = ($row['grace_since'] ?? $this->now()) + $this->graceDays * self::DAY;

        if ($this->graceDays > 0 && $this->now() < $endsAt) {
            return $this->status(State::Grace, 'notification_license_grace', Entitlements::fromCache($row['entitlements']), $row['checked_at'], $endsAt, cached: $cached);
        }

        return $this->status(State::Unreachable, 'notification_no_connection', checkedAt: $row['checked_at'], cached: $cached);
    }

    /**
     * @param  array{root_url: string, client_email: string, license_code: string}  $row
     * @param  array<mixed>  $data
     */
    private function saveActive(array $row, Entitlements $entitlements, array $data): Status
    {
        $now = $this->now();
        $failed = $this->save([
            'checked_at' => $now, 'grace_since' => null, 'retry_at' => null, 'refusal' => null,
            'entitlements' => $entitlements->toArray(),
        ] + $row);

        return $failed ?? $this->status(State::Active, 'notification_license_ok', $entitlements, $now, data: $data);
    }

    /**
     * Reads + verifies license.json. Returns the signed licence payload, or a failing Status.
     *
     * @return array<string, mixed>|Status
     */
    private function verifiedFile(string $appUrl, ?string $expectedCode): array|Status
    {
        if (! $this->licenseFile || ! is_file($this->licenseFile)) {
            return $this->status(State::NotInstalled, 'notification_license_corrupted');
        }

        return $this->verifiedLicense((string) @file_get_contents($this->licenseFile), $appUrl, $expectedCode);
    }

    /**
     * Verifies licence file contents. Returns the signed licence payload, or a failing Status.
     *
     * @return array<string, mixed>|Status
     */
    private function verifiedLicense(string $contents, string $appUrl, ?string $expectedCode): array|Status
    {
        $corrupted = $this->status(State::Invalid, 'notification_license_corrupted');

        $file = json_decode($contents, true);

        if (! is_array($file) || ! is_string($file['license'] ?? null) || ! is_string($file['signature'] ?? null)) {
            return $corrupted;
        }

        // The signed message is the pre-serialized JSON string: verify it byte for byte.
        if (! $this->signedBy($file['license'], $file['signature']) || ! is_array($license = json_decode($file['license'], true))) {
            return $corrupted;
        }

        /** @var array<string, mixed> $license */
        if ($expectedCode !== null && $expectedCode !== ($license['license_code'] ?? null)) {
            return $corrupted;
        }

        // Binding set in billing: comma-separated domains, or (IP-only binding) IPs. Neither = any host.
        $allowed = array_values(array_filter(array_map(
            fn (string $v): string => self::host(trim($v)),
            explode(',', (string) (($license['license_domain'] ?? '') ?: ($license['license_ip'] ?? ''))),
        )));

        if ($appUrl === '' || ($allowed && ! in_array(self::host($appUrl), $allowed, true))) {
            return $this->status(State::Invalid, 'notification_invalid_domain');
        }

        if ((int) ($license['product_id'] ?? 0) !== $this->productId) {
            return $this->status(State::Invalid, 'notification_invalid_product');
        }
        // 1 = active, 2 = suspended, anything else = cancelled (same as billing's online check)
        $status = (int) ($license['license_status'] ?? 0);

        if ($status !== 1) {
            return $status === 2
                ? $this->status(State::Suspended, 'notification_license_suspended')
                : $this->status(State::Cancelled, 'notification_license_cancelled');
        }

        try {
            $machineId = MachineId::hashedId($this->machineScope ?? (string) $this->productId);
        } catch (RuntimeException) {
            return $this->status(State::Invalid, 'notification_machine_id_unavailable');
        }

        if (! hash_equals($machineId, (string) ($license['license_machine_id'] ?? ''))) {
            return $this->status(State::Invalid, 'notification_invalid_machine');
        }

        // Only the licence expiry blocks the app; updates/support expiry are exposed via entitlements.
        if ($this->fileEntitlements($license)->expired('license', $this->today())) {
            return $this->status(State::Expired, 'notification_license_expired');
        }

        unset($license['scheme_query']);

        return $license;
    }

    /** @param  array<string, mixed>  $license */
    private function fileEntitlements(array $license): Entitlements
    {
        $legacy = [
            'license' => $license['license_expire_date'] ?? null,
            'updates' => $license['license_updates_date'] ?? null,
            'support' => $license['license_support_date'] ?? null,
        ];

        if (! is_array($license['entitlements'] ?? null)) {
            return Entitlements::unrestricted($legacy);
        }

        // Signed together with the rest of the file, so no separate signature. A malformed block denies.
        return Entitlements::fromArray($license['entitlements'] + ['expires' => $legacy]) ?? Entitlements::none();
    }

    /**
     * DATABASE mode entitlements. notification_data isn't covered by billing's response signature,
     * so billing signs the block itself: Ed25519 over "product_id|license_code|root_url|<json>".
     * Returns null when a block is present but can't be trusted.
     *
     * @param  array<mixed>  $data
     * @param  array{root_url: string, license_code: string}  $row
     */
    private function serverEntitlements(array $data, array $row): ?Entitlements
    {
        if (! array_key_exists('entitlements', $data)) {
            return Entitlements::unrestricted([
                'license' => $data['license_expire_date'] ?? null,
                'updates' => $data['license_updates_date'] ?? null,
                'support' => $data['license_support_date'] ?? null,
            ]);
        }

        $json = $data['entitlements'];
        $sig = $data['entitlements_signature'] ?? null;

        if (! is_string($json) || ! is_string($sig)
            || ! $this->signedBy($this->productId.'|'.$row['license_code'].'|'.$row['root_url'].'|'.$json, $sig)
            || ! is_array($decoded = json_decode($json, true))
            || ! ($entitlements = Entitlements::fromArray($decoded))) {
            return null;
        }

        // A fresh answer is expected: refuse old signed blocks replayed by a man in the middle.
        $issued = $entitlements->issuedAt?->getTimestamp();

        return $issued !== null && abs($this->now() - $issued) <= self::DAY ? $entitlements : null;
    }

    /* ---------------------------------------------------------------- protocol */

    /**
     * POST to billing with the standard signed payload and check its signed reply.
     *
     * @param  array{root_url: string, client_email: string, license_code: string}  $row
     * @param  array<string, string>  $extra
     * @return array{reached: bool, case: string, text: string, data: array<mixed>}
     */
    private function callServer(string $path, array $row, array $extra = []): array
    {
        $today = gmdate('Y-m-d', $this->now());
        $res = $this->network->post($this->serverUrl().$path, $extra + [
            'product_id' => $this->productId,
            'client_email' => $row['client_email'],
            'license_code' => $row['license_code'],
            'root_url' => $row['root_url'],
            'installation_hash' => hash('sha256', $row['root_url'].$row['client_email'].$row['license_code']),
            'license_signature' => $this->signature($today.$row['root_url'].$row['client_email'].$row['license_code'].$this->productId),
        ], 10, $this->verifySsl, $row['root_url']);

        if ($res->failed()) {
            return ['reached' => false, 'case' => 'notification_no_connection', 'text' => '', 'data' => []];
        }
        $expected = $this->signature($this->productId.$row['license_code'].$row['client_email'].$row['root_url'].$today, prefixIps: true);

        if ($expected === '' || ! hash_equals($expected, $res->header('notification_server_signature'))) {
            return ['reached' => false, 'case' => 'notification_invalid_response', 'text' => '', 'data' => []];
        }

        $data = json_decode($res->header('notification_data') ?: '[]', true);

        return ['reached' => true, 'case' => $res->header('notification_case'), 'text' => $res->header('notification_text'), 'data' => is_array($data) ? $data : []];
    }

    /** sha256 bound to billing's resolved IPs (billing computes the same). */
    private function signature(string $data, bool $prefixIps = false): string
    {
        $ips = implode('', $this->serverIps());

        return $ips === '' ? '' : hash('sha256', $prefixIps ? $ips.$data : $data.$ips);
    }

    private function signedBy(string $message, string $signatureB64): bool
    {
        $sig = base64_decode($signatureB64, true);
        $key = base64_decode((string) $this->publicKey, true);

        return is_string($sig) && is_string($key)
            && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && strlen($sig) === SODIUM_CRYPTO_SIGN_BYTES
            && sodium_crypto_sign_verify_detached($sig, $message, $key);
    }

    /** @return list<string> */
    private function serverIps(): array
    {
        return $this->network->ips(self::host($this->serverUrl()));
    }

    /** @param  array{root_url: string, license_code: string}  $row */
    private function reportVersion(array $row): void
    {
        if ($this->version === null) {
            return;
        }
        $this->network->post($this->serverUrl().'/update-latest-version?'.http_build_query([
            'version' => $this->version,
            'licenseCode' => $row['license_code'],
            'url' => $row['root_url'],
        ]), ['_' => 1], 10, $this->verifySsl); // best effort
    }

    /** Billing's answer → State. Updates/support expiry never block the app. */
    private static function stateFor(string $case): State
    {
        return match ($case) {
            'notification_license_ok', 'notification_updates_expired', 'notification_support_expired' => State::Active,
            'notification_license_expired' => State::Expired,
            'notification_license_suspended' => State::Suspended,
            'notification_license_cancelled' => State::Cancelled,
            default => State::Invalid,
        };
    }

    /* ------------------------------------------------------------ cache row */

    /** Atomic replace: the old file stays until the new one is fully written. */
    private function writeLicenseFile(string $contents): bool
    {
        $path = (string) $this->licenseFile;
        is_dir(dirname($path)) || @mkdir(dirname($path), 0775, true);
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($tmp, $contents, LOCK_EX) === false || ! @rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /** @param  array<string, mixed>  $row */
    private function save(array $row): ?Status
    {
        $keys = ['root_url', 'client_email', 'license_code', 'checked_at', 'grace_since', 'retry_at', 'refusal', 'entitlements'];
        $row = array_intersect_key($row, array_flip($keys));
        $row['mac'] = $this->mac($row);

        try {
            $this->store->put($this->pluginName, $row);
        } catch (RuntimeException $e) {
            return $this->status(State::Misconfigured, 'notification_write_error', errors: ['notification_write_error'], data: ['error' => $e->getMessage()]);
        }

        return null;
    }

    /** @param  array<string, mixed>  $row */
    private function mac(array $row): string
    {
        unset($row['mac']);
        ksort($row);

        return hash_hmac('sha256', (string) json_encode($row), $this->salt.'|'.$this->pluginName);
    }

    /**
     * Tamper checks on the cached row.
     *
     * @param  array<string, mixed>  $row
     */
    private function rowIsValid(array $row): bool
    {
        foreach (['root_url', 'client_email', 'license_code', 'mac'] as $k) {
            if (! is_string($row[$k] ?? null)) {
                return false;
            }
        }
        foreach (['grace_since', 'retry_at'] as $k) {
            if (! (($row[$k] ?? null) === null || is_int($row[$k]))) {
                return false;
            }
        }

        if (! is_int($row['checked_at'] ?? null) || ! is_array($row['entitlements'] ?? null) || ! (($row['refusal'] ?? null) === null || is_string($row['refusal']))) {
            return false;
        }

        $future = $this->now() + self::DAY; // clock rolled back, or dates forged

        return hash_equals($this->mac($row), (string) $row['mac'])
            && $row['checked_at'] <= $future
            && ($row['grace_since'] ?? 0) <= $future
            && self::isValidUrl((string) $row['root_url'])
            && $this->accessedFromLicensedUrl((string) $row['root_url']);
    }

    /* ---------------------------------------------------------------- helpers */

    /**
     * @param  list<string>  $errors
     * @param  array<mixed>  $data
     */
    private function status(
        State $state,
        string $case,
        ?Entitlements $entitlements = null,
        ?int $checkedAt = null,
        ?int $graceEndsAt = null,
        bool $graceStarted = false,
        bool $cached = false,
        array $errors = [],
        string $serverText = '',
        array $data = [],
    ): Status {
        $at = fn (?int $t): ?DateTimeImmutable => $t === null ? null : (new DateTimeImmutable)->setTimestamp($t);

        return new Status($state, $case, $entitlements ?? Entitlements::none(), $at($checkedAt), $at($graceEndsAt), $graceStarted, $cached, $errors, $serverText, $data);
    }

    /** @param  list<string>  $errors */
    private function misconfigured(array $errors): Status
    {
        return $this->status(State::Misconfigured, 'notification_script_corrupted', errors: $errors);
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    private function today(): DateTimeImmutable
    {
        return (new DateTimeImmutable)->setTimestamp($this->now());
    }

    private function serverUrl(): string
    {
        return rtrim($this->serverUrl, '/');
    }

    /** In a web request the current URL must sit under the licensed URL. CLI always passes. */
    private function accessedFromLicensedUrl(string $rootUrl): bool
    {
        $current = self::currentUrl();

        if ($current === null) {
            return true;
        }
        $under = fn (string $base): bool => str_starts_with(self::bare($current).'/', self::bare($base).'/');

        return $under($rootUrl) || ($this->allowIpAccess && ! empty($_SERVER['SERVER_ADDR']) && $under('http://'.$_SERVER['SERVER_ADDR']));
    }

    private function appUrl(): ?string
    {
        if ($this->rootUrl !== null && $this->rootUrl !== '') {
            return $this->rootUrl;
        }
        $current = self::currentUrl();

        return $current === null ? null : preg_replace('#/[^/]*$#', '', (string) strtok($current, '?'));
    }

    private static function currentUrl(): ?string
    {
        if (empty($_SERVER['HTTP_HOST']) || empty($_SERVER['SCRIPT_NAME'])) {
            return null;
        }
        $https = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        return ($https ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME'];
    }

    /** "https://www.a.com/app/index.php" -> "a.com/app" for URL prefix comparison. */
    private static function bare(string $url): string
    {
        $url = strtolower((string) strtok($url, '?#'));
        $url = (string) preg_replace(['#^[a-z][a-z0-9+.-]*://#', '#^www\.#', '#/[^/]*\.php$#'], '', $url);

        return rtrim($url, '/');
    }

    private static function host(string $url): string
    {
        $host = parse_url(str_contains($url, '://') ? $url : "http://$url", PHP_URL_HOST) ?: '';

        return (string) preg_replace('/^www\./i', '', strtolower($host));
    }

    private static function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false && ctype_alnum(substr($url, -1));
    }

    private static function validKey(?string $key): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached')
            && $key !== null
            && strlen((string) base64_decode($key, true)) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
    }

    /** @return list<string> */
    private function inputErrors(string $rootUrl, string $email, string $code): array
    {
        $errors = [];

        if (! self::isValidUrl($rootUrl)) {
            $errors[] = 'input_invalid_url';
        }

        if (trim($email) === '' && trim($code) === '') {
            $errors[] = 'input_empty_license_data';
        }

        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'input_invalid_email';
        }

        return $errors;
    }
}
