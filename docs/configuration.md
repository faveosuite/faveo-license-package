# Configuration

- [The identity file](#the-identity-file)
- [Environment values](#environment-values)
- [Without an identity file](#without-an-identity-file)
- [Configuration reference](#configuration-reference)

## The identity file

Faveo Invoicing writes two files into every download, at paths **each product chooses**. In Faveo Invoicing these are the product's `config_file_path` (the identity file) and `license_file_path` (the licence file). Point the package at the same paths. Relative paths are resolved from `base_path()`, so you can copy them exactly as they are in Faveo Invoicing.

```dotenv
LICENSE_IDENTITY_FILE=storage/app/myproduct/product.json   # = config_file_path in Faveo Invoicing
LICENSE_SALT=long-random-string-never-change-it            # unique per installation

# Offline (FILE mode) only:
LICENSE_FILE=storage/app/myproduct/license.json            # = license_file_path in Faveo Invoicing
```

- The identity file (`KEY=VALUE` or JSON, same key names) supplies `server_url`, `product_id`, `product_key`, `public_key`, `mode` and the installed version. Its keys are in the [Server protocol](invoicing-protocol.md#identity-file).
- **Explicit values win.** A value set in `.env` or the published config overrides the file. This is useful for pointing a local copy at a test Faveo Invoicing server. Installers must therefore **not** write these keys to `.env`.
- **The salt** seals the local licence cache. Generate it in your installer (for example `Str::random(40)`), write it to `.env`, and **never change it**. It is never read from the identity file.
- `php artisan license:status` shows which values came from the identity file.

## Environment values

| Variable | Config key | Notes |
|---|---|---|
| `LICENSE_IDENTITY_FILE` | `identity_file` | Path of Faveo Invoicing's identity file (JSON or `KEY=VALUE`) |
| `LICENSE_FILE` | `license_file` | Path of `license.json`. Required in `FILE` mode |
| `LICENSE_SALT` | `salt` | 16+ random characters, never change it |
| `LICENSE_SERVER_URL` | `server_url` | Faveo Invoicing URL. Only needed without an identity file |
| `LICENSE_PRODUCT_ID` | `product_id` | Only needed without an identity file |
| `LICENSE_PUBLIC_KEY` | `public_key` | Only needed without an identity file |
| `LICENSE_PRODUCT_KEY` | `updates.product_key` | Only needed without an identity file |
| `LICENSE_MODE` | `mode` | `FILE` or `DATABASE` |
| `LICENSE_ADDONS_DISCOVER` | `addons_discover` | Glob of plugin identity files |

## Without an identity file

Set the values directly:

```dotenv
LICENSE_SERVER_URL=https://invoicing.example.com
LICENSE_PRODUCT_ID=5
LICENSE_PUBLIC_KEY=base64-ed25519-public-key
LICENSE_PRODUCT_KEY=update-key-from-faveo-invoicing
LICENSE_MODE=DATABASE                                      # online; or FILE for offline
LICENSE_SALT=long-random-string-never-change-it

# Offline (FILE mode) only:
LICENSE_FILE=storage/app/myproduct/license.json
```

## Configuration reference

`config/license-client.php`, after publishing with `php artisan vendor:publish --tag=license-client-config`.

### Faveo Invoicing and licence

| Key | Default | Meaning |
|---|---|---|
| `identity_file` | `null` | Path of Faveo Invoicing's identity file (JSON or `KEY=VALUE`, see [Server protocol](invoicing-protocol.md#identity-file)) |
| `server_url`, `product_id`, `public_key` | `null` | Faveo Invoicing URL, this product's ID, Faveo Invoicing's Ed25519 public key. Taken from the identity file when `null` |
| `verify_ssl` | `true` | Verify Faveo Invoicing's TLS certificate. Disable only on hosts with a broken CA bundle |
| `mode` | `null` → `FILE` | `FILE` (offline) or `DATABASE` (online) |
| `salt` | `LICENSE_SALT` | Seals the local cache. Unique per installation, never change it |
| `license_file` | `null` | Path of `license.json`. Required in `FILE` mode |
| `root_url` | `null` → `APP_URL` | This installation's URL. Must match the licensed domain |
| `days` | `5` | Days between full checks (1-365). Between checks, verifying is a cache read |
| `grace_days` | `7` | Online only: days to keep working while the product can't reach Faveo Invoicing (`0` = off) |
| `retry_minutes` | `60` | Online only: how often the connection is retried during grace or after a refusal |
| `report_version` | `null` | Online only: the installed version to report to Faveo Invoicing. `null` = don't report |
| `allow_ip_access`, `root_ip`, `nameservers` | `false`, `''`, `[]` | Extra anti-spoofing checks. Each adds a DNS lookup per full check. Set only values that never change |

### Cache store

| Key | Default | Meaning |
|---|---|---|
| `store.driver` | `database` | `database` (the `license_cache` table) or `file` |
| `store.connection` | `null` | Database connection for the `database` driver. `null` = the default |
| `store.table` | `license_cache` | Table name |
| `store.path` | `storage/app/license-client/cache.json` | JSON file for the `file` driver |

### Plugins and middleware

| Key | Default | Meaning |
|---|---|---|
| `addons` | `[]` | Plugins listed by hand, for example `'calendar' => ['product_id' => 12, 'license_file' => '...']` |
| `addons_discover` | `null` | Glob of plugin identity files, for example `plugins/*/license-client.json`. See [Plugins](plugins.md) |
| `middleware.except` | `license*`, `login`, `logout` | Paths the middleware never blocks |

### Updates

| Key | Default | Meaning |
|---|---|---|
| `updates.product_key` | `null` | This product's update key in Faveo Invoicing. Taken from the identity file when `null` |
| `updates.install_dir` | app root | Where updates are extracted |
| `updates.backup_dir` | `storage/app/license-client/backups` | Where backups are kept. Must be on the same filesystem as `install_dir` |
| `updates.replace_dirs` | `[]` | Directories emptied before extracting, with sub-paths to keep, for example `['vendor' => ['acme/runtime-state']]` |
| `updates.current_version` | `null` | Installed version. Taken from the identity file's `version` when `null`. Older offline update files need `--force` |
| `updates.require_signed_archives` | `true` | Refuse unsigned online archives. Turn off only temporarily |
| `updates.max_archive_mb` | `1024` | Largest archive accepted |
| `updates.keep_backups` | `2` | Backups kept |
| `updates.timeout` | `300` | Download timeout in seconds |
| `updates.temp_dir` | `null` | Where archives are downloaded. `null` = the system temp directory |
| `updates.run_migrations` | `true` | Run `migrate --force` after the files are in place |
| `updates.maintenance` | `true` | Use maintenance mode while files change |
