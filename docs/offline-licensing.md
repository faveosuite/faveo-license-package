# Offline licensing (`FILE` mode)

The product checks a signed licence file on its own server and **never contacts Faveo Invoicing**: no licence checks, no update checks, not even DNS lookups. The licence file is locked to the server's machine ID and the domain.

Set `LICENSE_MODE=FILE` (or let Faveo Invoicing set `mode` in the identity file) and make sure `LICENSE_FILE` points at the licence file path.

## Activation, renewal and moving to a new server

All three use the same steps:

```
Product                          Faveo Invoicing (any computer)       Product
───────                          ─────────────────────────────        ───────
shows activation code   ──▶      "Activate offline" page:     ──▶     upload the licence file
(domain + machine ID)            paste the code, download             → checked, then saved
                                 the licence file
```

### In code

```php
use Faveo\LicenseClient\Laravel\Facades\License;

// 1. Show the activation code to the customer
$code = License::activationCode($licenseCode);

// 3. Install the uploaded licence file
$status = License::import($request->file('license')->get());

if (! $status->ok()) {
    return back()->withErrors(['license' => License::message($status)]);
}
```

### From the command line

```bash
php artisan license:request LICENSE-CODE      # prints the activation code
php artisan license:import license.json       # installs the downloaded file
```

## Detecting when activation is needed

`$status->needsActivation()` is true when a new licence file is required: the product is not activated yet, the server changed, or the domain changed. Show the activation code and the upload box on your licence page in those cases:

```php
$status = License::status();

if ($status->needsActivation()) {
    // show License::activationCode($licenseCode) and an upload box
}
```

See [Using the licence](usage.md#building-a-licence-page) for a complete licence page.

## Safety

- **A wrong upload never breaks a working licence.** `import()` checks the file completely before it replaces the current one: Faveo Invoicing's signature, product, domain, this server's machine ID, status and expiry.
- If a download from Faveo Invoicing already contains the licence file (Faveo Invoicing stamps it for File-mode orders), `php artisan license:install LICENSE-CODE` activates it directly.
- Online update commands refuse to run in offline mode. Updates come from an update file, see [Updates](updates.md#offline-licence-update-from-a-file).
