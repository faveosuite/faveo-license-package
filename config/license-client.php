<?php

declare(strict_types=1);

/*
 * Licence + update settings for this product. Publish with:
 *   php artisan vendor:publish --tag=license-client-config
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Identity file
    |--------------------------------------------------------------------------
    | Path of the product identity file that billing stamps into every download: JSON, or KEY=VALUE
    | lines like faveoconfig.ini (the product's `config_file_path` in billing — use the same path
    | here). Relative paths are resolved from base_path(). null = don't read one.
    |
    | Keys (same in both formats, any case): SERVER_URL, PRODUCT_ID, PRODUCT_KEY, ED25519_PUBLIC_KEY,
    | LICENSE_MODE, APP_VERSION. Values set explicitly below (or in .env) always win; salt and
    | root_url are never read from it.
    */

    'identity_file' => env('LICENSE_IDENTITY_FILE'),

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    | server_url  Billing root URL, no trailing slash.
    | product_id  This product's ID in billing.
    | public_key  Billing's Ed25519 public key (base64, 44 chars). Verifies license.json (FILE mode),
    |             signed entitlements (DATABASE mode) and update archives.
    | verify_ssl  Only disable on hosts with a broken CA bundle.
    |
    | Leave any of these null to take it from the identity file.
    */

    'server_url' => env('LICENSE_SERVER_URL'),
    'product_id' => env('LICENSE_PRODUCT_ID'),
    'public_key' => env('LICENSE_PUBLIC_KEY'),
    'verify_ssl' => true,

    /*
    |--------------------------------------------------------------------------
    | Licence
    |--------------------------------------------------------------------------
    | mode          'FILE' = offline signed license.json, 'DATABASE' = online check against billing.
    |               null = from the identity file, else FILE.
    | salt          Seals the local cache row. 16+ random chars, unique per install, NEVER change it
    |               afterwards (the cache stops validating until the licence is re-installed).
    | license_file  FILE mode: path of the signed license.json (the product's `license_file_path` in
    |               billing — use the same path here). Relative paths are resolved from base_path().
    | root_url      This app's URL. null = APP_URL. Must match the licensed domain.
    | days          Days between full checks (1-365); verify() is a cache read in between.
    | grace_days    DATABASE mode: keep working this many days while billing is unreachable (0 = off).
    |               A definite refusal (expired, suspended...) is never covered by grace.
    | retry_minutes How often to retry billing while in grace or after a refusal.
    | report_version DATABASE mode: report the installed version to billing (null = don't).
    */

    'mode' => env('LICENSE_MODE'),
    'salt' => env('LICENSE_SALT'),
    'license_file' => env('LICENSE_FILE'),
    'root_url' => null,
    'days' => 5,
    'grace_days' => 7,
    'retry_minutes' => 60,
    'report_version' => null,

    // Extra anti-spoofing (each adds a DNS lookup per full check). Only set values that never change.
    'allow_ip_access' => false,
    'root_ip' => '',
    'nameservers' => [],

    /*
    |--------------------------------------------------------------------------
    | Cache store
    |--------------------------------------------------------------------------
    | 'database' uses the license_cache table (php artisan migrate), 'file' a JSON file.
    */

    'store' => [
        'driver' => 'database',
        'connection' => null,
        'table' => 'license_cache',
        'path' => storage_path('app/license-client/cache.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Add-ons
    |--------------------------------------------------------------------------
    | Each add-on has its own licence. Keys override the main settings above; the machine ID is
    | always scoped to the main product. Use: License::for('calendar')->allows('...').
    |
    | 'calendar' => ['product_id' => 12, 'license_file' => storage_path('app/license-client/calendar.json')],
    */

    'addons' => [],

    // Plugins installed separately: glob of the identity files billing stamps into each plugin's
    // download (the plugin product's config_file_path), relative to base_path(). Each file names its
    // add-on and carries its product_id, keys, licence file path and version. null = off.
    // e.g. 'plugins/*/license-client.json'. Entries in `addons` above win key by key.
    'addons_discover' => env('LICENSE_ADDONS_DISCOVER'),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    | Web requests that fail the check get a 403 (LicenseException — render it however you like).
    | Paths matching `except` are never blocked, so admins can still reach the licence page.
    */

    'middleware' => [
        'except' => ['license*', 'login', 'logout'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Updates
    |--------------------------------------------------------------------------
    | product_key             This product's update key in billing (null = from the identity file).
    | install_dir             Archives are extracted here.
    | backup_dir              Must be on the same filesystem as install_dir (dirs are renamed, not copied).
    | replace_dirs            Emptied before extracting when the archive ships them; value = sub-paths to
    |                         keep. This package's own directory is always kept.
    | require_signed_archives Refuse archives without billing's Ed25519 signature. Only set false
    |                         temporarily while billing is being upgraded.
    | run_migrations          Run `migrate --force` after the files are in place.
    | maintenance             Put the app in maintenance mode while files change.
    */

    'updates' => [
        'product_key' => env('LICENSE_PRODUCT_KEY'),
        'install_dir' => base_path(),
        'backup_dir' => storage_path('app/license-client/backups'),
        'replace_dirs' => [],
        'require_signed_archives' => true,
        'max_archive_mb' => 1024,
        'keep_backups' => 2,
        'timeout' => 300,
        'temp_dir' => null,
        'run_migrations' => true,
        'current_version' => null,   // installed version; offline update files older than this need --force
        'maintenance' => true,
    ],

];
