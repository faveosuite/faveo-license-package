# Updates

Every update, online or offline, goes through the same safe steps:

1. Verify the licence.
2. Verify Faveo Invoicing's **signature** on the update.
3. Inspect the zip. It refuses `../` paths, absolute paths, symlinks and archives over `max_archive_mb`.
4. Check free disk space and file permissions.
5. **Back up** the files it will overwrite, and move each `replace_dirs` directory aside.
6. Run `artisan down`, then extract.
7. Run `migrate --force`, clear the config, route, view and event caches, then `artisan up`. The application cache is not touched.

## When something fails

- **Extraction fails:** the backup is restored automatically and the app comes back up.
- **A migration fails:** the files are *not* rolled back, because the database may already be half migrated. The app stays in maintenance mode. Fix the problem, or run `php artisan license:rollback`.
- **The database is never backed up by the package.** Back it up yourself before updating, for example in your update screen or in the command that runs the update.
- **Two updates can't run at once**, and this package's own directory is never removed by `replace_dirs`.

## Offline licence: update from a file

The customer downloads the update file from Faveo Invoicing and installs it:

```bash
php artisan license:update --file=myproduct-v9.2.0-offline.zip
```

It is refused unless:

- it is signed by Faveo Invoicing,
- it is for this product and made for **this customer's licence**,
- the version was released within the licence's update period (versions released before the period ended still install after it ends),
- it isn't older than `updates.current_version` (use `--force` to downgrade on purpose).

## Online licence: update from Faveo Invoicing

```bash
php artisan license:update-check    # versions available, and the update period
php artisan license:update v9.2.0   # download and install
```

### Showing "update available" in your app

```php
$newer = License::updater()->available(config('app.version'), 'official'); // or 'pre_release', 'beta'
// [] = up to date. Otherwise the public versions newer than yours, oldest first,
// each with its version, title, description and dependencies.

$expires = License::expiresAt('updates');   // null = never
$allowed = $expires === null || $expires > now();
```

`available()` talks to Faveo Invoicing, so only call it in `DATABASE` mode. In `FILE` mode, updates come from the update file.

It replaces Faveo Invoicing's `/version/latest`, `/new-version-available` and `/api/billingRelease` calls, and `expiresAt('updates')` replaces `/v1/checkUpdatesExpiry`.

## After an update

Database changes ship as normal Laravel migrations in the update. `migrate --force` runs them (`updates.run_migrations`). `UpdateApplied` fires when everything is done, with the new `$version`. See [Events](events.md).

## Updating from your own code

Run updates from a queued job or the command line, **not** inside a web request: large archives take time, and the app goes into maintenance mode partway through.

The artisan commands are thin wrappers around `UpdateRunner`, which does the licence gate, maintenance mode, migrations, cache clearing and events:

```php
use Faveo\LicenseClient\Laravel\UpdateRunner;
use Faveo\LicenseClient\Updater\UpdateException;

class InstallUpdate implements ShouldQueue
{
    public function __construct(public string $version) {}

    public function handle(UpdateRunner $runner): void
    {
        try {
            $runner->run($this->version);                  // online: download and install
            // $runner->runFile($path, force: false);      // offline: install an uploaded update file
        } catch (UpdateException $e) {
            report($e);   // $e->rolledBack tells you if the files were restored
        }
    }
}
```

- `$runner->rollback()` restores the latest backup.
- `License::updater()->backups()` lists the backups, newest first.
- `UpdateApplied` and `UpdateFailed` fire either way, so one listener covers both the command line and queued updates.
