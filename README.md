<div align="center">
    <h1>License Client</h1>
</div>

Licence checks, feature entitlements and safe updates for Laravel products sold through Faveo billing.

- **Two licence modes:**
  - **Offline** (`FILE`): a signed licence file, locked to the server. It never contacts billing.
  - **Online** (`DATABASE`): a check against billing every few days, with a grace period if billing is down.
- **Entitlements:** features, limits (agents, departments…) and expiry dates, signed by billing.
- **Safe updates:** a signed archive or update file. The files are backed up first and restored automatically if extraction fails.
- **Laravel integration:** a facade, middleware, a Blade directive, artisan commands and events.

Requires PHP 8.2+, Laravel 12+, and the `curl`, `sodium` and `zip` extensions.

## Contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Offline licence (FILE mode)](#offline-licence-file-mode)
- [Online licence (DATABASE mode)](#online-licence-database-mode)
- [Using the licence in your app](#using-the-licence-in-your-app)
- [Plugins](#plugins)
- [Updates](#updates)
- [Commands](#commands)
- [Configuration reference](#configuration-reference)
- [Events](#events)
- [Billing protocol](#billing-protocol)
- [Testing](#testing)

## Installation

The package is private. Point Composer at the repository, then require it:

```json
"repositories": [{"type": "vcs", "url": "git@github.com:sadhasivam-ladybirdweb/faveo-license-client.git"}]
```

```bash
composer require faveo/license-client
php artisan vendor:publish --tag=license-client-config   # config/license-client.php
php artisan migrate                                       # creates the license_cache table
```

The service provider registers itself and its migrations automatically. The translations and the migrations can also be published, with `--tag=license-client-lang` and `--tag=license-client-migrations`.

## Configuration

Billing writes two files into every download, at paths **each product chooses**. In billing these are the product's `config_file_path` and `license_file_path`. Point the package at the same paths. Relative paths are resolved from `base_path()`, so you can copy them exactly as they are in billing:

```dotenv
LICENSE_IDENTITY_FILE=storage/app/myproduct/product.json   # = config_file_path in billing
LICENSE_FILE=storage/app/myproduct/license.json            # = license_file_path in billing
LICENSE_SALT=long-random-string-never-change-it            # unique per installation
```

- **The identity file** supplies `server_url`, `product_id`, `product_key`, `public_key` and `mode`.
- **Explicit values win.** A value set in `.env` or the published config overrides the file, which is useful for pointing a local copy at a test billing server. So installers must **not** write these keys to `.env`.
- **The salt** seals the local licence cache. Generate it in your installer (e.g. `Str::random(40)`), write it to `.env`, and **never change it**. It's never read from the identity file.

Without an identity file, set the values directly:

```dotenv
LICENSE_SERVER_URL=https://billing.example.com
LICENSE_PRODUCT_ID=5
LICENSE_PUBLIC_KEY=base64-ed25519-public-key
LICENSE_PRODUCT_KEY=update-key-from-billing
LICENSE_MODE=FILE                                          # or DATABASE
```

`php artisan license:status` shows which values came from the identity file.

## Offline licence (FILE mode)

The product checks the signed licence file on its own server and **never contacts billing**: no licence checks, no update checks, not even DNS lookups. The licence file is locked to the server's machine ID and the domain.

### Activation, renewal and new server

All three use the same steps:

```
Product                          Billing (any computer)               Product
───────                          ──────────────────────               ───────
shows activation code   ──▶      "Activate offline" page:     ──▶     upload the licence file
(domain + machine ID)            paste the code, download              → checked, then saved
                                 the licence file
```

```php
use Faveo\LicenseClient\Laravel\Facades\License;

// 1. Show the activation code
$code = License::activationCode($licenseCode);

// 3. Install the uploaded licence file
$status = License::import($request->file('license')->get());

if (! $status->ok()) {
    return back()->withErrors(['license' => License::message($status)]);
}
```

From the command line: `php artisan license:request LICENSE-CODE`, then `php artisan license:import license.json`.

- **A wrong upload never breaks a working licence.** `import()` checks the file completely before it replaces the current one: billing's signature, product, domain, this server's machine ID, status and expiry.
- **`$status->needsActivation()`** is true when a new licence file is needed: the product isn't activated yet, the server changed, or the domain changed. Show the activation code and the upload box on your licence page in those cases:

```php
$status = License::status();

if ($status->needsActivation()) {
    // show License::activationCode($licenseCode) and an upload box
}
```

If a download from billing already contains the licence file (billing stamps it for File-mode orders), `php artisan license:install LICENSE-CODE` activates it directly.

## Online licence (DATABASE mode)

```bash
php artisan license:install LICENSE-CODE --email=owner@customer.com
```

The product asks billing every `days` (5 by default). Between checks, verifying is a single cache read.

**Grace period:**
- If billing can't be reached, the product keeps working for `grace_days` (7 by default), using the last entitlements it cached.
- Billing is retried at most every `retry_minutes`.
- A definite answer such as expired, suspended or cancelled is **never** covered by grace.
- `GraceStarted` fires once per outage.

**Upgrading from the old licence scripts:** `php artisan migrate` (which every update runs) activates the licences the old `apl_*` scripts left in `faveo_license` and `plugin_license` with billing, once, like `license:install` would. The customer does nothing. Add-ons are matched to `addons` by `PLUGIN_NAME`, ignoring case; unconfigured ones are skipped. The old tables are left alone. If billing couldn't be reached at that moment, run `php artisan license:import-legacy`.

## Using the licence in your app

```php
use Faveo\LicenseClient\Laravel\Facades\License;

License::ok();                            // licence valid (active, or in grace)
License::allows('sla');                   // feature granted
License::limit('agents');                 // ?int, null = unlimited
License::withinLimit('agents', $count);   // $count <= limit
License::expiresAt('updates');            // ?DateTimeImmutable
License::for('calendar')->allows('sync'); // add-on licence (see `addons` in the config)

$status = License::status();              // Faveo\LicenseClient\Status
$status->state;                           // State::Active, Grace, Expired, Suspended, Cancelled, Invalid, ...
License::message($status);                // translated text for the customer
```

The status is checked at most once per request.

### Routes and views

```php
Route::middleware('license')->group(...);                 // 403 unless the licence is valid
Route::middleware('license.feature:sla')->group(...);     // 403 unless the feature is granted
Route::middleware('license:calendar')->group(...);        // add-on licence
```

```blade
@licensed('reports')
    <a href="{{ route('reports.index') }}">Reports</a>
@endlicensed
```

The middleware throws `Faveo\LicenseClient\Laravel\LicenseException`, a 403 carrying `$status` and `$feature`. To show your own page instead, in `bootstrap/app.php`:

```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(fn (\Faveo\LicenseClient\Laravel\LicenseException $e) =>
        response()->view('license.blocked', ['status' => $e->status], 403));
})
```

Paths in `middleware.except` (default `license*`, `login`, `logout`) are never blocked, so keep your licence page under `/license…`. That way admins can reach it even with a broken licence.

### Entitlements

- **No entitlements from billing** (billing not upgraded yet): every feature is allowed and every limit is unlimited.
- **Entitlements sent:** access is **deny by default**. Unknown features are off and unknown limits are 0. `$status->entitlements->present()` tells you which case applies.
- **Expiry dates:**
  - Only the **licence** expiry blocks the app.
  - The **updates** expiry controls which versions may be installed.
  - The **support** date is information only.
- **Enforcing limits is your code's job:**

```php
if (! License::withinLimit('agents', Agent::count() + 1)) {
    return back()->withErrors(__('Your plan allows :n agents.', ['n' => License::limit('agents')]));
}
```

## Plugins

Plugins have their own licence and their own updates, and they can be installed separately, after the product. **No licence code goes into any plugin.** Billing writes an identity file into each plugin's download, the same way it does for the product, and the package finds them:

```php
// config/license-client.php in the product, set once
'addons_discover' => 'plugins/*/license-client.json',
```

- **Installing or updating a plugin:** use the same command as the product. The file's product ID tells the package it's a plugin, including one that isn't installed yet:
  ```bash
  php artisan license:update --file=calendar-v1.2.0-offline.zip
  ```
- **After it's installed**, `License::for('calendar')` works. Activate its licence like the product's: `License::activationCode($code, 'calendar')` and `License::import($file, 'calendar')`, or `--addon=calendar` on the commands.
- **Each plugin has its own backups**, and `php artisan license:rollback --addon=calendar` restores only that plugin.
- **A plugin update never uses the product's `replace_dirs`**, so it can't empty the product's `vendor/`.
- **A plugin and the product never update at the same time.** They share one lock.
- **Plugin update files carry the main licence code**, because billing issues plugin licences on the customer's main licence.

Plugins can also be listed by hand in `addons`. Those entries win over the discovered values, key by key.

## Updates

Every update, online or offline, goes through the same safe steps:

1. Verify the licence.
2. Verify billing's **signature** on the update.
3. Inspect the zip. It refuses `../` paths, absolute paths, symlinks and archives over `max_archive_mb`.
4. Check free disk space and file permissions.
5. **Back up** the files it will overwrite, and move each `replace_dirs` directory aside.
6. Run `artisan down`, then extract.
7. Run `migrate --force`, clear the config, route, view and event caches, then `artisan up`. The application cache is not touched.

**What happens when something fails:**
- **Extraction fails:** the backup is restored automatically and the app comes back up.
- **A migration fails:** the files are *not* rolled back, because the database may already be half migrated. The app stays in maintenance mode. Fix the problem, or run `php artisan license:rollback`.
- **The database is never backed up by the package.** Back it up yourself before updating, e.g. in your update screen or in the command that runs the update.
- **Two updates can't run at once**, and this package's own directory is never removed by `replace_dirs`.

### Offline licence: update from a file

The customer downloads the update file from billing and installs it:

```bash
php artisan license:update --file=myproduct-v9.2.0-offline.zip
```

It's refused unless:
- it's signed by billing,
- it's for this product and made for **this customer's licence**,
- the version was released within the licence's update period (versions released before the period ended still install after it ends),
- it isn't older than `updates.current_version` (use `--force` to downgrade on purpose).

Online update commands refuse to run in offline mode.

### Online licence: update from billing

```bash
php artisan license:update-check    # versions available, and the update period
php artisan license:update v9.2.0   # download and install
```

In code, e.g. for an "update available" notice. It replaces billing's `/version/latest`, `/new-version-available` and `/api/billingRelease` calls:

```php
$newer = License::updater()->available(config('app.version'), 'official'); // or 'pre_release', 'beta'
// [] = up to date. Otherwise the public versions newer than yours, oldest first,
// each with its version, title, description and dependencies.

$expires = License::expiresAt('updates');   // replaces /v1/checkUpdatesExpiry; null = never
$allowed = $expires === null || $expires > now();
```

`available()` talks to billing, so only call it in DATABASE mode. In FILE mode, updates come from the update file.

Add-ons the customer can install, for an "install plugin" list. It replaces billing's `/api/pluginInfo`:

```php
$plugins = License::updater()->plugins(License::license()->licenseCode());
// each row: product_id, product_name, product_key, product_description, version, license_code, path, dependency
```

### After an update

Database changes ship as normal Laravel migrations in the update; `migrate --force` runs them (`updates.run_migrations`). `UpdateApplied` fires when everything is done, with the new `$version`.

Run updates from a queued job or the command line, not inside a web request: large archives take time, and the app goes into maintenance mode partway through.

## Commands

| Command | What it does |
|---|---|
| `license:install {code} [--email=] [--addon=] [--url=]` | Activate. Online: asks billing. Offline: uses the licence file billing already stamped into the download |
| `license:request {code} [--addon=] [--url=]` | Offline: print the activation code for billing's "Activate offline" page |
| `license:import {file} [--addon=] [--url=]` | Offline: install a licence file from billing (activation, renewal, new server) |
| `license:status [--addon=] [--force]` | Show the state, where the identity values came from, entitlements and expiry dates |
| `license:update {version} [--addon=]` | Online: download and install a version of the product or a plugin |
| `license:update --file= [--force]` | Offline: install an update file, for the product or a plugin (recognised automatically) |
| `license:update-check` | Online: list versions from billing |
| `license:rollback [--addon=]` | Restore the files from the most recent update backup of the product or a plugin |
| `license:uninstall [--addon=]` | Remove the licence from this installation (the licence file, if any, stays). A tampered licence doesn't need this: `license:install` replaces it |
| `license:import-legacy [--table=] [--plugin-table=]` | Online: activate the licences left by the old licence scripts. Runs once on `migrate`; this retries it |

`license:update` also takes `--no-migrate`.

## Configuration reference

`config/license-client.php`, after publishing:

| Key | Default | Meaning |
|---|---|---|
| `identity_file` | `null` | Path of billing's identity JSON (= `config_file_path` in billing) |
| `server_url`, `product_id`, `public_key` | `null` | Billing URL, this product's ID, billing's Ed25519 public key (from the identity file when null) |
| `mode` | `null` → `FILE` | `FILE` (offline) or `DATABASE` (online) |
| `salt` | `LICENSE_SALT` | Seals the local cache. Unique per installation, never change it |
| `license_file` | `null` | Path of `license.json` (= `license_file_path` in billing). Required in FILE mode |
| `root_url` | `null` → `APP_URL` | This installation's URL, must match the licensed domain |
| `days` | `5` | Days between full checks |
| `grace_days`, `retry_minutes` | `7`, `60` | Online only: outage grace period, and how often billing is retried |
| `store.driver` | `database` | Where the licence cache lives: `database` (the `license_cache` table) or `file` |
| `addons` | `[]` | Plugins listed by hand, e.g. `'calendar' => ['product_id' => 12, 'license_file' => '...']` |
| `addons_discover` | `null` | Glob of plugin identity files (= each plugin product's `config_file_path`), e.g. `plugins/*/license-client.json` |
| `middleware.except` | `license*`, `login`, `logout` | Paths the middleware never blocks |
| `updates.product_key` | `null` | This product's update key in billing (from the identity file when null) |
| `updates.install_dir`, `updates.backup_dir` | app root, `storage/app/license-client/backups` | Where updates go, and where backups are kept (same filesystem) |
| `updates.replace_dirs` | `[]` | Dirs emptied before extracting, with sub-paths to keep, e.g. `['vendor' => ['acme/runtime-state']]` |
| `updates.current_version` | `null` | Installed version (from the identity file's `version` when null). Older offline update files need `--force` |
| `updates.require_signed_archives` | `true` | Refuse unsigned online archives. Turn off only temporarily |
| `updates.run_migrations`, `updates.maintenance` | `true`, `true` | Run migrations, and use maintenance mode, during updates |

## Events

| Event | When |
|---|---|
| `LicenseChecked` | After every full (non-cached) licence check |
| `LicenseFailed` | A full check failed (expired, invalid, unreachable…) |
| `GraceStarted` | Online only: billing became unreachable and the grace period began, once per outage |
| `UpdateApplied` | An update finished, with the new `$version` |
| `UpdateFailed` | An update failed. `$error->rolledBack` says whether the files were restored |

All events are in `Faveo\LicenseClient\Laravel\Events`.

## Billing protocol

What billing must send, for the billing team. Everything new is signed with the same Ed25519 key as `license.json`. The existing endpoints and signatures stay as they are: `/api/licenseInstall`, `/api/licenseVerify`, `/api/ConnectionTest`, `/update-latest-version`, `/api/getVersions`, `/api/getAllVersions` and `/api/downloadFile`. The package doesn't use `/api/licenseScheme` (it creates its own `license_cache` table) or `/api/fetchQuery` (database changes are Laravel migrations).

### Identity file

The JSON billing writes at the product's `config_file_path`:

```json
{"server_url": "https://billing.example.com", "product_id": 5, "product_key": "...", "public_key": "base64...", "mode": "FILE"}
```

- `mode` is `FILE` for File-mode orders.
- Optional `version`: the version in this download. It's used to refuse downgrades.
- **Plugin products:** the same file, at the plugin's own `config_file_path` inside its folder, plus `"addon": "calendar"` (the plugin's name) and `"license_file": "<the plugin's license_file_path>"`.
- **Plugin update files** use the customer's **main** licence code as `license_code`.
- **Never put the salt in this file.** It would be the same for every customer, and it's readable by anyone who downloads the product.

### Activation code (offline)

The text the customer pastes on the "Activate offline" page: `FLC1.` followed by the base64url encoding (no padding) of:

```json
{"v": 1, "product_id": 7, "license_code": "...", "domain": "app.test", "machine_id": "<hashed>", "addon": null}
```

- `domain` has no scheme and no `www.`.
- `machine_id` is the hashed ID, scoped to the **main** product, so add-ons send the same value.
- `addon` is the add-on name, or null.

Billing should:
1. Check the licence code belongs to `product_id`.
2. Bind `domain` and `machine_id`. If the licence is already bound to a different machine, refuse, or count it as a server move within the allowed limit.
3. Return the signed `license.json` for download.

### Offline update file

A zip, downloaded from My Orders, containing:

| File | Content |
|---|---|
| `update.zip` | The normal stamped update, stored uncompressed |
| `manifest.json` | `{"v": 1, "product_id": 5, "version": "v9.2.0", "released_at": "2026-10-01", "license_code": "...", "sha256": "<hex sha256 of update.zip>"}` |
| `manifest.sig` | base64 Ed25519 over the exact bytes of `manifest.json` |

Build it only when the licence is active and `released_at` falls within the licence's update period.

### Entitlements

**In `license.json`** (offline): inside the signed `license` payload:

```json
"entitlements": {
  "features": ["sla", "reports"],
  "limits":   {"agents": 25, "departments": null},
  "expires":  {"license": "2027-01-01", "updates": "2026-12-01", "support": "2026-12-01"}
}
```

**In the `licenseInstall` / `licenseVerify` replies** (online): two keys in `notification_data`:
- `entitlements`: the block above as a JSON **string**, including `"issued_at": "<ISO-8601 UTC now>"`.
- `entitlements_signature`: base64 Ed25519 over `"{product_id}|{license_code}|{root_url}|{entitlements}"`, using the request's exact strings.

Blocks issued more than 24 hours from the client's clock are refused, which stops replays.

### Online update archives

Two headers on the `/api/downloadFile` reply:
- `archive_sha256`: hex sha256 of the zip.
- `archive_signature`: base64 Ed25519 over `"{product_id}|{version_number}|{archive_sha256}"`.

Until billing sends these, online clients need `updates.require_signed_archives = false`. Set that only temporarily.

### Licensed add-ons

`POST /api/licensedPlugins`, signed like `/api/getAllVersions` (the same fields, plus `license_code`). Billing:
- checks `license_code` is an active licence of `product_id`,
- finds the customer from that licence's order (never from anything else the client sends),
- replies `notification_operation_ok` with `{"plugins": [...]}`: one row per installable add-on with `product_id`, `product_name`, `product_key`, `product_description`, `version`, `license_code`, `path` (its install folder) and `dependency`,
- or `notification_license_not_found`.

## Testing

```bash
composer test    # PHPStan, Pint, 100% type coverage, Pest
```

## Changelog

See [CHANGELOG](CHANGELOG.md).

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## License

See [LICENSE.md](LICENSE.md).
