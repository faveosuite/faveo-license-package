# Plugins

Plugins have their own licence and their own updates, and they can be installed separately, after the product. **No licence code goes into any plugin.** Faveo Invoicing writes an identity file into each plugin's download, the same way it does for the product, and the package finds them.

## Discovering plugins

Set once in the product's `config/license-client.php`:

```php
'addons_discover' => 'plugins/*/license-client.json',
```

The glob is relative to `base_path()` and matches each plugin product's `config_file_path` in Faveo Invoicing. The file format is in the [Server protocol](invoicing-protocol.md#identity-file).

You can also list plugins by hand in `addons`. Those entries win over the discovered values, key by key:

```php
'addons' => [
    'calendar' => ['product_id' => 12, 'license_file' => storage_path('app/license-client/calendar.json')],
],
```

## Installing and updating a plugin

Use the same command as for the product. The file's product ID tells the package it's a plugin, including one that isn't installed yet:

```bash
php artisan license:update --file=calendar-v1.2.0-offline.zip
```

For online licences: `php artisan license:update v1.2.0 --addon=calendar`.

To offer plugins to install in your UI:

```php
$plugins = License::updater()->plugins(License::license()->licenseCode());
// each row: product_id, product_name, product_key, product_description, version, license_code, path, dependency
```

## Licensing a plugin

Once installed, `License::for('calendar')` works. Activate its licence the way you activate the product's:

```php
License::activationCode($code, 'calendar');
License::import($file, 'calendar');
License::install($email, $code, 'calendar');
```

Or use `--addon=calendar` on the commands. Protect routes with `->middleware('license:calendar')`.

## Rules

- **Each plugin has its own backups**, and `php artisan license:rollback --addon=calendar` restores only that plugin.
- **A plugin update never uses the product's `replace_dirs`**, so it can't empty the product's `vendor/`.
- **A plugin and the product never update at the same time.** They share one lock.
- **Plugin update files carry the main licence code**, because Faveo Invoicing issues plugin licences on the customer's main licence.
