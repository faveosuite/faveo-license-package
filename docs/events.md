# Events and messages

## Events

All events are in `Faveo\LicenseClient\Laravel\Events`.

| Event | Properties | When |
|---|---|---|
| `LicenseChecked` | `$addon`, `$status` | After every full (non-cached) licence check. `$addon` is `''` for the product |
| `LicenseFailed` | `$addon`, `$status` | A check returned a state that blocks the app (expired, invalid, unreachable…) |
| `GraceStarted` | `$addon`, `$status` | Online only: the product could no longer reach Faveo Invoicing and the grace period began. Once each time the connection is lost |
| `UpdateApplied` | `$version` | An update finished: files, migrations and caches are done |
| `UpdateFailed` | `$version`, `$error` | An update failed. `$error->rolledBack` says whether the files were restored |

```php
use Faveo\LicenseClient\Laravel\Events\GraceStarted;

Event::listen(GraceStarted::class, function (GraceStarted $event) {
    Notification::route('mail', 'admin@example.com')
        ->notify(new LicenseGraceStarted($event->status->graceEndsAt));
});
```

When `UpdateFailed` has `$error->rolledBack === false` (a post-update step failed), the app stays in maintenance mode for an admin to fix or to run `php artisan license:rollback`.

## Messages and translations

`License::message($status)` returns a translated customer-facing text, looked up by `$status->case` and then by the state. For a status with `errors`, show `License::text($error)` for each one.

Publish the strings to edit or translate them:

```bash
php artisan vendor:publish --tag=license-client-lang   # lang/vendor/license-client/en/messages.php
```

Every key is in `lang/en/messages.php`:

- states (`active`, `expired`, `tampered`…)
- `notification_*` cases Faveo Invoicing or the client can return
- `config_*` configuration errors
- `input_*` install input errors
- `feature_not_licensed`, shown when a feature middleware blocks a request

Add a folder per locale, for example `lang/vendor/license-client/fr/messages.php`.
