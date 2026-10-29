<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Fills product identity config from the file billing stamps into each download, at the path the
 * application sets in license-client.identity_file. The file is JSON or KEY=VALUE lines (billing's
 * faveoconfig.ini); both use the same key names, in any case. Only keys the application left null
 * are filled; salt and root_url are never read from it (they belong to the installation, not the product).
 *
 * The outcome is recorded in license-client.identity for `license:status`.
 */
final class IdentityFile
{
    /** file key (billing's faveoconfig.ini names) => config key */
    private const KEYS = [
        'SERVER_URL' => 'server_url',
        'PRODUCT_ID' => 'product_id',
        'PRODUCT_KEY' => 'updates.product_key',
        'ED25519_PUBLIC_KEY' => 'public_key',
        'LICENSE_MODE' => 'mode',
        'APP_VERSION' => 'updates.current_version',
    ];

    /** file key => add-on entry key, for a plugin's identity file (which also names its add-on in ADDON). */
    private const ADDON_KEYS = [
        'PRODUCT_ID' => 'product_id',
        'PRODUCT_KEY' => 'product_key',
        'ED25519_PUBLIC_KEY' => 'public_key',
        'LICENSE_MODE' => 'mode',
        'LICENSE_FILE' => 'license_file',
        'APP_VERSION' => 'version',
    ];

    public static function merge(Config $config, string $basePath): void
    {
        if (is_array($config->get('license-client.identity'))) {
            return; // config:cache already holds the merged values; `license:update` clears it
        }

        $path = $config->get('license-client.identity_file');

        if (! is_string($path) || $path === '') {
            $config->set('license-client.identity', ['file' => null, 'state' => 'not_set', 'used' => []]);

            return;
        }

        $path = self::resolve($path, $basePath);
        $data = self::read($path);

        if ($data === null) {
            $config->set('license-client.identity', ['file' => $path, 'state' => is_file($path) ? 'unreadable' : 'missing', 'used' => []]);

            return;
        }

        $used = [];
        foreach (self::KEYS as $fileKey => $configKey) {
            $value = $data[$fileKey] ?? null;

            if ((is_string($value) && $value !== '' || is_int($value)) && $config->get("license-client.$configKey") === null) {
                $config->set("license-client.$configKey", $value);
                $used[] = $fileKey;
            }
        }
        $config->set('license-client.identity', ['file' => $path, 'state' => 'loaded', 'used' => $used]);
    }

    /**
     * The identity file with upper-cased keys: a JSON object, or KEY=VALUE lines. null when unreadable
     * or empty.
     *
     * @return array<array-key, mixed>|null
     */
    private static function read(string $path): ?array
    {
        $contents = is_readable($path) ? (string) @file_get_contents($path) : '';
        $data = json_decode($contents, true);

        if (! is_array($data)) {
            // Split on the first "=" only (base64 values end in "="); parse_ini_* chokes on ( ! " in values.
            $data = [];
            foreach (preg_split('/\R/', $contents) ?: [] as $line) {
                $parts = explode('=', $line, 2);

                if (isset($parts[1]) && ($key = trim($parts[0])) !== '') {
                    $data[$key] = trim(trim($parts[1]), '"\'');
                }
            }
        }
        $data = array_change_key_case($data, CASE_UPPER);

        if (is_string($data['PRODUCT_ID'] ?? null) && ctype_digit($data['PRODUCT_ID'])) {
            $data['PRODUCT_ID'] = (int) $data['PRODUCT_ID'];
        }

        return $data ?: null;
    }

    /** Absolute paths as-is; relative ones from the app root, so the value can match billing's zip path. */
    public static function resolve(string $path, string $basePath): string
    {
        return preg_match('#^([/\\\\]|[A-Za-z]:[/\\\\])#', $path) === 1 ? $path : rtrim($basePath, '/\\').'/'.ltrim($path, '/\\');
    }

    /**
     * Register plugins from the identity files billing stamps into each plugin's download, found with
     * the glob the application sets in license-client.addons_discover (relative to base_path()).
     * Each file names its add-on (ADDON) and carries PRODUCT_ID, plus optionally PRODUCT_KEY,
     * ED25519_PUBLIC_KEY, LICENSE_MODE, LICENSE_FILE and APP_VERSION. Entries in license-client.addons
     * win key by key.
     *
     * The outcome is recorded in license-client.addons_discovered for `license:status`.
     */
    public static function discoverAddons(Config $config, string $basePath): void
    {
        if (is_array($config->get('license-client.addons_discovered'))) {
            return; // config:cache already holds the result; installing a plugin clears the config cache
        }

        $pattern = $config->get('license-client.addons_discover');
        $found = [];
        $invalid = [];

        if (is_string($pattern) && $pattern !== '') {
            foreach (glob(self::resolve($pattern, $basePath)) ?: [] as $file) {
                $data = self::read($file);
                $name = $data['ADDON'] ?? null;

                if ($data === null || ! is_string($name) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) !== 1
                    || ! is_int($data['PRODUCT_ID'] ?? null) || isset($found[$name])) {
                    $invalid[] = $file;

                    continue;
                }
                $entry = [];
                foreach (self::ADDON_KEYS as $fileKey => $entryKey) {
                    $value = $data[$fileKey] ?? null;

                    if (is_int($value) || (is_string($value) && $value !== '')) {
                        $entry[$entryKey] = $value;
                    }
                }
                $configured = (array) $config->get("license-client.addons.$name", []);
                $config->set("license-client.addons.$name", $configured + $entry);
                $found[$name] = $file;
            }
        }
        $config->set('license-client.addons_discovered', ['pattern' => $pattern, 'found' => $found, 'invalid' => $invalid]);
    }
}
