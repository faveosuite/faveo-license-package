<?php

declare(strict_types=1);

use Faveo\LicenseClient\Laravel\IdentityFile;
use Faveo\LicenseClient\Laravel\LicenseManager;
use Illuminate\Config\Repository;

function identityConfig(array $licenseClient): Repository
{
    return new Repository(['license-client' => $licenseClient + ['updates' => ['product_key' => null]]]);
}

beforeEach(function () {
    $this->root = tempDir();
    mkdir($this->root.'/storage/app/myproduct', 0777, true);
    file_put_contents($this->root.'/storage/app/myproduct/product.json', json_encode([
        'server_url' => 'https://billing.test',
        'product_id' => 5,
        'product_key' => 'KEY-5',
        'ed25519_public_key' => 'PUB',
        'license_mode' => 'FILE',
        'apl_salt' => 'must-never-be-read',
        'salt' => 'must-never-be-read',
        'root_url' => 'https://must-never-be-read.test',
    ]));
});

it('fills unset identity values from the path the application configures, matching key names in any case', function () {
    $config = identityConfig(['identity_file' => 'storage/app/myproduct/product.json', 'product_id' => null, 'server_url' => null, 'public_key' => null, 'mode' => null, 'salt' => null, 'root_url' => null]);
    IdentityFile::merge($config, $this->root);

    expect($config->get('license-client.product_id'))->toBe(5)
        ->and($config->get('license-client.server_url'))->toBe('https://billing.test')
        ->and($config->get('license-client.updates.product_key'))->toBe('KEY-5')
        ->and($config->get('license-client.mode'))->toBe('FILE')
        ->and($config->get('license-client.salt'))->toBeNull()
        ->and($config->get('license-client.root_url'))->toBeNull()
        ->and($config->get('license-client.identity.state'))->toBe('loaded');
});

it('reads the KEY=VALUE identity file billing stamps (faveoconfig.ini) with the same key names as JSON', function () {
    file_put_contents($this->root.'/storage/faveoconfig.ini', implode("\n", [
        'APL_SALT=must-never-be-read',
        'PRODUCT_KEY=KEY-8',
        'APP_NAME=Faveo (Pro) !',
        'APP_VERSION=v9.4.3',
        'PRODUCT_ID=8',
        'LICENSE_MODE=DATABASE',
        'ED25519_PUBLIC_KEY=PUB=',
        'SERVER_URL="https://billing.test"',
        '',
    ]));
    $config = identityConfig(['identity_file' => 'storage/faveoconfig.ini', 'product_id' => null, 'server_url' => null, 'public_key' => null, 'mode' => null, 'salt' => null, 'updates' => ['product_key' => null, 'current_version' => null]]);
    IdentityFile::merge($config, $this->root);

    expect($config->get('license-client.product_id'))->toBe(8)
        ->and($config->get('license-client.server_url'))->toBe('https://billing.test')
        ->and($config->get('license-client.updates.product_key'))->toBe('KEY-8')
        ->and($config->get('license-client.public_key'))->toBe('PUB=')
        ->and($config->get('license-client.mode'))->toBe('DATABASE')
        ->and($config->get('license-client.updates.current_version'))->toBe('v9.4.3')
        ->and($config->get('license-client.salt'))->toBeNull()
        ->and($config->get('license-client.identity.state'))->toBe('loaded');
});

it('lets explicit application config win over the file', function () {
    $config = identityConfig(['identity_file' => $this->root.'/storage/app/myproduct/product.json', 'product_id' => 99, 'mode' => 'DATABASE', 'server_url' => null, 'public_key' => null]);
    IdentityFile::merge($config, '/unused');

    expect($config->get('license-client.product_id'))->toBe(99)
        ->and($config->get('license-client.mode'))->toBe('DATABASE')
        ->and($config->get('license-client.identity.used'))->toBe(['SERVER_URL', 'PRODUCT_KEY', 'ED25519_PUBLIC_KEY']);
});

it('reads nothing when the application sets no path, and reports a missing or broken file', function () {
    $none = identityConfig(['identity_file' => null, 'product_id' => null]);
    IdentityFile::merge($none, $this->root);
    expect($none->get('license-client.product_id'))->toBeNull()->and($none->get('license-client.identity.state'))->toBe('not_set');

    $missing = identityConfig(['identity_file' => 'nope.json', 'product_id' => null]);
    IdentityFile::merge($missing, $this->root);
    expect($missing->get('license-client.identity.state'))->toBe('missing');

    file_put_contents($this->root.'/broken.json', '{not json');
    $broken = identityConfig(['identity_file' => 'broken.json', 'product_id' => null]);
    IdentityFile::merge($broken, $this->root);
    expect($broken->get('license-client.identity.state'))->toBe('unreadable');
});

it('does not re-merge a cached config', function () {
    $config = identityConfig(['identity_file' => 'storage/app/myproduct/product.json', 'product_id' => 7, 'identity' => ['file' => 'x', 'state' => 'loaded', 'used' => ['product_id']]]);
    IdentityFile::merge($config, $this->root);

    expect($config->get('license-client.identity.used'))->toBe(['product_id']);
});

it('resolves relative licence file paths from the app root and requires one in FILE mode', function () {
    config()->set('license-client.license_file', null);
    app()->forgetScopedInstances();

    expect(app(LicenseManager::class)->verify()->errors)->toContain('config_missing_license_file');
    expect(IdentityFile::resolve('storage/app/x/license.json', '/srv/app'))->toBe('/srv/app/storage/app/x/license.json')
        ->and(IdentityFile::resolve('/abs/license.json', '/srv/app'))->toBe('/abs/license.json');
});

it('shows where the identity came from in license:status', function () {
    $this->artisan('license:status')->expectsOutputToContain('not set (license-client.identity_file)');
});

it('discovers plugins from the identity files billing stamps into them', function () {
    mkdir($this->root.'/plugins/Calendar', 0777, true);
    mkdir($this->root.'/plugins/Broken', 0777, true);
    file_put_contents($this->root.'/plugins/Calendar/license-client.json', json_encode([
        'ADDON' => 'calendar', 'PRODUCT_ID' => 12, 'PRODUCT_KEY' => 'CAL-KEY', 'LICENSE_FILE' => 'plugins/Calendar/license.json', 'APP_VERSION' => 'v3', 'APL_SALT' => 'ignored',
    ]));
    file_put_contents($this->root.'/plugins/Broken/license-client.json', '{"ADDON": "no product id"}');
    $config = identityConfig(['addons_discover' => 'plugins/*/license-client.json', 'addons' => ['calendar' => ['product_key' => 'EXPLICIT']]]);

    IdentityFile::discoverAddons($config, $this->root);

    expect($config->get('license-client.addons.calendar'))->toBe([
        'product_key' => 'EXPLICIT', // explicit config wins
        'product_id' => 12,
        'license_file' => 'plugins/Calendar/license.json',
        'version' => 'v3',
    ])->and($config->get('license-client.addons_discovered.found'))->toHaveKey('calendar')
        ->and($config->get('license-client.addons_discovered.invalid'))->toHaveCount(1);
});

it('discovers plugins from KEY=VALUE identity files too', function () {
    mkdir($this->root.'/plugins/Calendar', 0777, true);
    file_put_contents($this->root.'/plugins/Calendar/faveoconfig.ini', "ADDON=calendar\nPRODUCT_ID=12\nPRODUCT_KEY=CAL-KEY\nLICENSE_FILE=plugins/Calendar/license.json\n");
    $config = identityConfig(['addons_discover' => 'plugins/*/faveoconfig.ini', 'addons' => []]);

    IdentityFile::discoverAddons($config, $this->root);

    expect($config->get('license-client.addons.calendar'))->toBe([
        'product_id' => 12,
        'product_key' => 'CAL-KEY',
        'license_file' => 'plugins/Calendar/license.json',
    ]);
});

it('uses the identity file version as the installed version', function () {
    file_put_contents($this->root.'/storage/app/myproduct/product.json', json_encode(['PRODUCT_ID' => 5, 'APP_VERSION' => 'v9.1.0']));
    $config = identityConfig(['identity_file' => 'storage/app/myproduct/product.json', 'product_id' => null, 'updates' => ['product_key' => null, 'current_version' => null]]);
    IdentityFile::merge($config, $this->root);

    expect($config->get('license-client.updates.current_version'))->toBe('v9.1.0');
});
