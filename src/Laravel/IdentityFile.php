<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Fills product identity config from the JSON billing stamps into each download, at the path the
 * application sets in license-client.identity_file. Only keys the application left null are filled;
 * salt and root_url are never read from it (they belong to the installation, not the product).
 *
 * The outcome is recorded in license-client.identity for `license:status`.
 */
final class IdentityFile
{
    /** file key => config key */
    private const KEYS = [
        'server_url' => 'server_url',
        'product_id' => 'product_id',
        'product_key' => 'updates.product_key',
        'public_key' => 'public_key',
        'mode' => 'mode',
        'version' => 'updates.current_version',
    ];

    /** Keys a plugin's identity file may set on its add-on entry. */
    private const ADDON_KEYS = ['product_id', 'product_key', 'public_key', 'mode', 'license_file', 'version'];

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
        $data = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($data)) {
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

    /** Absolute paths as-is; relative ones from the app root, so the value can match billing's zip path. */
    public static function resolve(string $path, string $basePath): string
    {
        return preg_match('#^([/\\\\]|[A-Za-z]:[/\\\\])#', $path) === 1 ? $path : rtrim($basePath, '/\\').'/'.ltrim($path, '/\\');
    }

    /**
     * Register plugins from the identity files billing stamps into each plugin's download, found with
     * the glob the application sets in license-client.addons_discover (relative to base_path()).
     * Each file names its add-on ("addon") and carries product_id, plus optionally product_key,
     * public_key, mode, license_file and version. Entries in license-client.addons win key by key.
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
                $data = json_decode((string) @file_get_contents($file), true);
                $name = is_array($data) ? ($data['addon'] ?? null) : null;

                if (! is_array($data) || ! is_string($name) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) !== 1
                    || ! is_int($data['product_id'] ?? null) || isset($found[$name])) {
                    $invalid[] = $file;

                    continue;
                }
                $entry = array_filter(
                    array_intersect_key($data, array_flip(self::ADDON_KEYS)),
                    fn (mixed $v): bool => is_int($v) || (is_string($v) && $v !== ''),
                );
                $configured = (array) $config->get("license-client.addons.$name", []);
                $config->set("license-client.addons.$name", $configured + $entry);
                $found[$name] = $file;
            }
        }
        $config->set('license-client.addons_discovered', ['pattern' => $pattern, 'found' => $found, 'invalid' => $invalid]);
    }
}
