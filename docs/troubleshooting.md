# Troubleshooting

Start with `php artisan license:status --force`. It shows the state, where each value came from, entitlements and expiry dates.

| Symptom | Cause and fix |
|---|---|
| State `misconfigured` | Read `$status->errors`. Usually a missing `LICENSE_SALT` (16+ characters), a missing `LICENSE_FILE` in `FILE` mode, or an invalid public key |
| State `tampered` after changing `.env` | `LICENSE_SALT` changed. Restore it, or run `license:install` / `license:import` again |
| `notification_invalid_domain` or `notification_invalid_machine` | The domain or the server changed. Repeat the [offline activation](offline-licensing.md) (`needsActivation()` is true) |
| State `unreachable` in `DATABASE` mode | The product can't reach Faveo Invoicing and `grace_days` is used up. Check `server_url`, the server's outbound HTTPS and firewall, DNS and `verify_ssl` |
| Admins locked out of the licence page | Keep the page under `/license…`, or add its path to `middleware.except` |
| Online update refused with `archive_unsigned` | Faveo Invoicing doesn't sign archives yet. Upgrade Faveo Invoicing, or temporarily set `updates.require_signed_archives` to `false` |
| App stuck in maintenance mode after an update | A migration failed, so the files were kept. Fix it and run `php artisan up`, or run `php artisan license:rollback` |
| `.env` changes ignored | Run `php artisan config:clear`. `license:status` shows whether each value came from the identity file or from config |
| Online commands refuse in offline mode | Expected. In `FILE` mode use `license:update --file=` |
| Every feature is allowed | Faveo Invoicing hasn't sent entitlements yet. Access is deny-by-default only once it does ([Entitlements](usage.md#entitlements)) |
