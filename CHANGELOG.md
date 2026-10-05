# Release Notes

## [Unreleased](https://github.com/sadhasivam-ladybirdweb/faveo-license-client/compare/v1.0.0...main)

- `Updater::available($current, $releaseType)`: public versions newer than `$current` on the release channel. It replaces billing's `/version/latest`, `/new-version-available` and `/api/billingRelease`.
- Fixed: DATABASE mode now reads billing's `license_expire_date` / `license_updates_date` / `license_support_date`, so `expiresAt('updates')` works without entitlements.
- Fixed: update API replies read the data from the body when billing sends no `notification_data` header, so `versions()` and `version()` return billing's data.
- Fixed: when billing refuses a download (e.g. `notification_version_not_found`), `apply()` now reports billing's reason instead of `archive_unsigned`.
- Fixed: billing's licence refusals (`notification_domain_in_use`, `notification_domain_required`, `notification_invalid_ip`, `notification_host_banned`, `notification_license_not_found`, `notification_license_limit`, `notification_installation_not_found`, `notification_invalid_signature`, `notification_product_not_found`, `notification_product_inactive`, `notification_unknown_error`) now have their own message instead of the generic "not valid for this installation".
- Upgrading from the old licence scripts: `migrate` activates the licences left in `faveo_license` / `plugin_license` once. `license:import-legacy` retries it.
- `license:uninstall`, and `license:install` now replaces a tampered licence instead of refusing with "already installed".
- `Reply::ok()`: billing answered `notification_operation_ok`.
- `Updater::plugins($licenseCode)`: the add-ons the licence's customer can install (product key, licence code, latest version, install folder), from billing's signed `/api/licensedPlugins`. It replaces the unsigned `/api/pluginInfo`.
- Removed `Updater::queries()` and `UpdateApplied::$queries`: billing has no `/api/fetchQuery` and stores no update SQL; database changes ship as Laravel migrations. Update files no longer carry `queries`.
- Fixed: the package loads its migrations, so a plain `php artisan migrate` creates `license_cache` as documented.

## v1.0.0 - 2026-09-25

First release. Replaces the `apl_*` / `aus_*` scripts and `newScript`, and speaks billing's existing API.

- FILE (signed `license.json`) and DATABASE (online) licensing with a sealed cache row.
- DATABASE mode grace period with a retry throttle; refusals are cached and never covered by grace.
- Entitlements: features, limits and expiry dates, signed by billing; deny-by-default once billing sends them.
- Safe updates: Ed25519-signed archives, zip inspection, backup + automatic rollback, self-protection in vendor/, update lock.
- Product identity file: billing stamps server_url / product_id / product_key / public_key / mode at a path each application configures (`identity_file`); explicit config wins, salt is never read from it. `license_file` has no default either.
- Offline activation: `License::activationCode()` / `license:request` builds the code for billing's "Activate offline" page; `License::import()` / `license:import` installs an uploaded licence file only after fully verifying it.
- Offline updates: `license:update --file=` installs billing's signed update file (checks signature, product, licence, update period, downgrade) through the same backup/rollback pipeline. Online update commands refuse in FILE mode, and FILE mode makes no DNS lookups.
- `Status::needsActivation()`: true when a new licence file is needed (not activated, server or domain changed); clearer re-activation messages.
- Updates clear only the framework caches (config, route, view, event), never the application cache or cache-backed sessions.
- Plugins: discovered from billing's per-plugin identity files (`addons_discover`), installed and updated separately with their own backups (`--addon`), never inheriting the product's `replace_dirs`, one shared update lock.
- Laravel integration: facade, `license` / `license.feature` middleware, `@licensed`, artisan commands, events, migration, translations.
