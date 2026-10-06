# Online licensing (`DATABASE` mode)

The product asks Faveo Invoicing whether the licence is valid. Between checks, verifying is a single cache read, so there is no per-request network cost.

Set `LICENSE_MODE=DATABASE` (or let Faveo Invoicing set `mode` in the identity file), run `php artisan migrate` to create the `license_cache` table, then activate.

## Activation

```bash
php artisan license:install LICENSE-CODE --email=owner@customer.com
```

Or in code:

```php
$status = License::install($email, $licenseCode);

if (! $status->ok()) {
    return back()->withErrors(['code' => License::message($status)]);
}
```

`install()` never throws on a refusal. It returns a `Status`. If a licence is already installed, uninstall it first with `License::uninstall()` or `php artisan license:uninstall`. A tampered licence needs no uninstall: `install` replaces it.

## When checks happen

The product asks Faveo Invoicing every `days` (5 by default). `php artisan license:status --force` runs a full check immediately, and `License::verify(force: true)` does the same in code.

## Grace period

The grace period keeps the product working when it **can't connect** to Faveo Invoicing for a check, for example because of a network problem, a firewall or a DNS failure. It does **not** extend an expired licence.

- While the product can't connect, it keeps working for `grace_days` (7 by default), using the last entitlements it cached.
- The connection is retried at most every `retry_minutes` (60 by default).
- **There is no grace after expiry.** When Faveo Invoicing answers that the licence is expired, suspended or cancelled, the app is blocked straight away. A definite answer is never covered by grace.
- The `GraceStarted` event fires once each time the connection is lost.
- When grace runs out, the state becomes `Unreachable` and the app is blocked.

Set `grace_days` to `0` to turn grace off.

## Upgrading from the old licence scripts

`php artisan migrate` (which every update runs) activates the licences that the old `apl_*` scripts left in `faveo_license` and `plugin_license` with Faveo Invoicing, once, like `license:install` would. The customer does nothing.

- Add-ons are matched to the `addons` config by `PLUGIN_NAME`, ignoring case. Unconfigured ones are skipped.
- The old tables are left alone.
- If the product couldn't reach Faveo Invoicing at that moment, run `php artisan license:import-legacy`. Use `--table=` and `--plugin-table=` if your tables have other names.

## Online updates

Online mode can download and install updates from Faveo Invoicing. See [Updates](updates.md#online-licence-update-from-faveo-invoicing).
