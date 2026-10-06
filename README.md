<div align="center">
    <h1>Faveo License Client</h1>
    <p>Licence checks, feature entitlements and safe updates for Laravel products sold through Faveo Invoicing.</p>
</div>

## What it does

| | |
|---|---|
| **Two licence modes** | **Online** (`DATABASE`): checks Faveo Invoicing every few days, with a grace period when the server can't reach it. **Offline** (`FILE`): a signed licence file locked to the server, never contacts Faveo Invoicing. |
| **Entitlements** | Features, limits (agents, departments…) and expiry dates, signed by Faveo Invoicing. |
| **Safe updates** | Signed archives or update files, backed up first and restored automatically if extraction fails. |
| **Plugins** | Separately installed add-ons with their own licence, updates and backups. No licence code inside the plugin. |
| **Laravel integration** | Facade, middleware, `@licensed` Blade directive, artisan commands and events. |

## Faveo Invoicing (the licence server)

This package is the client side of [Faveo Invoicing](https://github.com/faveosuite/faveo-invoicing-community), the invoicing and subscription system that sells your product, issues licences and signs entitlements and updates. Faveo Invoicing writes the identity file and licence file into every download, and this package reads them. For installing and using Faveo Invoicing itself, see its [README](https://github.com/faveosuite/faveo-invoicing-community#readme) and the [Faveo Invoicing Wiki](https://github.com/ladybirdweb/faveo-invoicing-community/wiki).

What Faveo Invoicing must send for this package to work is in the [Server protocol](docs/invoicing-protocol.md).

## Requirements

- PHP 8.2+
- Laravel 12 or 13
- PHP extensions: `curl`, `sodium`, `zip`, `json`

## Installation

The package is private. Point Composer at the repository, then require it:

```json
"repositories": [{"type": "vcs", "url": "git@github.com:sadhasivam-ladybirdweb/faveo-license-client.git"}]
```

```bash
composer require faveo/license-client
php artisan vendor:publish --tag=license-client-config   # optional: config/license-client.php
php artisan migrate                                       # creates the license_cache table
```

The service provider registers itself. Publish tags: `license-client-config`, `license-client-lang`, `license-client-migrations`, or `license-client` for all three.

## Quick start

**1. Configure** the values every product needs. Use the same path as the product's `config_file_path` in Faveo Invoicing:

```dotenv
LICENSE_IDENTITY_FILE=storage/app/myproduct/product.json
LICENSE_SALT=a-long-random-string-never-change-it
```

The mode comes from the identity file Faveo Invoicing writes (`DATABASE` for online orders). Without an identity file, set `LICENSE_MODE=DATABASE` yourself, because the default is `FILE`. See [Configuration](docs/configuration.md).

**2. Activate** the licence. Online is the main path ([online](docs/online-licensing.md)); for servers with no internet use the [offline](docs/offline-licensing.md) licence file, which also needs `LICENSE_FILE` (the product's `license_file_path`):

```bash
php artisan license:install LICENSE-CODE --email=owner@customer.com   # online
php artisan license:import license.json                               # offline
```

**3. Protect** your routes and features:

```php
use Faveo\LicenseClient\Laravel\Facades\License;

Route::middleware('license')->group(function () {
    Route::get('/reports', ReportsController::class)->middleware('license.feature:reports');
});

if (! License::withinLimit('agents', Agent::count() + 1)) {
    // plan limit reached
}
```

**4. Check** it works:

```bash
php artisan license:status --force
```

## Sample app

[Faveo Integration Sample](https://github.com/faveosuite/faveo-integration-sample) explains the Faveo Invoicing licence APIs and shows how to integrate them into a product.

- **Guides:** why a product needs licensing, how to configure it, how to call the licence functions in your product, and how updates work.
- **API tester:** enter the server URL, pick an API and send the request to see the reply.

This package already makes these API calls for you, so you don't need to code them yourself. Read the sample if you want to understand what is sent to Faveo Invoicing and what comes back.

## Documentation

| Guide | Read it to |
|---|---|
| [Configuration](docs/configuration.md) | Set up the identity file, `.env` values and every config key |
| [Online licensing](docs/online-licensing.md) | Run `DATABASE` mode: checks, grace period, upgrading from the old scripts |
| [Offline licensing](docs/offline-licensing.md) | Activate, renew and move a `FILE` mode licence |
| [Using the licence](docs/usage.md) | Facade, middleware, Blade, entitlements, `Status`, building a licence page |
| [Plugins](docs/plugins.md) | Licence and update plugins separately from the product |
| [Updates](docs/updates.md) | Online and offline updates, rollback, running updates from code |
| [Commands](docs/commands.md) | Every artisan command and option |
| [Events and messages](docs/events.md) | Listen to licence and update events, translate customer messages |
| [Troubleshooting](docs/troubleshooting.md) | Fix common errors |
| [Server protocol](docs/invoicing-protocol.md) | What [Faveo Invoicing](https://github.com/faveosuite/faveo-invoicing-community) must send (for its developers) |

## Testing

```bash
composer test        # PHPStan, Pint, 100% type coverage, Pest
composer test:unit   # Pest only
composer lint        # fix code style
composer serve       # Testbench workbench app
```

## Changelog

See [CHANGELOG](CHANGELOG.md).

## Contributing

Contributions are welcome via pull requests. Please review the [contributing guidelines](.github/CONTRIBUTING.md) first.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Commercial Support

For professional support or custom development, reach out via the [Faveo contact form](https://www.faveohelpdesk.com/contact-us/).

## License

See [LICENSE.md](LICENSE.md).
