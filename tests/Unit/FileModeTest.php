<?php

declare(strict_types=1);

use Faveo\LicenseClient\License;
use Faveo\LicenseClient\State;
use Faveo\LicenseClient\Store\FileStore;
use Faveo\LicenseClient\Tests\Support\FakeBilling;

beforeEach(function () {
    $this->dir = tempDir();
    $this->billing = new FakeBilling;
    $this->store = new FileStore($this->dir.'/cache.json');
    $this->file = $this->dir.'/license.json';
    $this->billing->writeLicenseFile($this->file, 7);
    $this->license = fn (array $over = []): License => new License(...$over + [
        'serverUrl' => 'https://billing.test',
        'productId' => 7,
        'salt' => 'a-long-random-salt-value',
        'store' => $this->store,
        'licenseFile' => $this->file,
        'publicKey' => $this->billing->publicKey,
        'rootUrl' => 'https://www.app.test',
        'network' => $this->billing,
        'clock' => $this->billing->clock(),
    ]);
});

afterEach(fn () => $_SERVER = array_diff_key($_SERVER, ['HTTP_HOST' => 1, 'SCRIPT_NAME' => 1]));

it('installs from the signed file and answers later checks from the cache', function () {
    $license = ($this->license)();

    expect($license->install('', 'WRONG')->case)->toBe('notification_license_corrupted');

    $status = $license->install('a@b.test', 'CODE-1');
    expect($status->state)->toBe(State::Active)
        ->and($status->data)->not->toHaveKey('scheme_query')
        ->and($status->entitlements->expired('updates'))->toBeTrue() // expired updates don't block the app
        ->and($license->install('a@b.test', 'CODE-1')->case)->toBe('notification_already_installed')
        ->and($license->verify()->cached)->toBeTrue()
        ->and($license->licenseCode())->toBe('CODE-1');
});

it('detects an edited cache row', function (array $edit) {
    $license = ($this->license)();
    $license->install('a@b.test', 'CODE-1');
    $this->store->put('', $edit + (array) $this->store->get(''));

    expect($license->verify()->state)->toBe(State::Tampered)
        ->and($license->licenseCode())->toBeNull();
})->with([
    'forged future date' => [['checked_at' => 32503680000]],
    'stolen code' => [['license_code' => 'STOLEN']],
    'granted features' => [['entitlements' => ['present' => false]]],
]);

it('refuses a forged or foreign licence file', function (array $over, State $state, string $case) {
    $license = ($this->license)();
    $license->install('a@b.test', 'CODE-1');
    $this->billing->writeLicenseFile($this->file, 7, $over);

    $status = $license->verify(force: true);
    expect($status->state)->toBe($state)->and($status->case)->toBe($case);
})->with([
    'expired' => [['license_expire_date' => '2000-01-01'], State::Expired, 'notification_license_expired'],
    'suspended' => [['license_status' => 2], State::Suspended, 'notification_license_suspended'],
    'cancelled' => [['license_status' => 0], State::Cancelled, 'notification_license_cancelled'],
    'other domain' => [['license_domain' => 'other.test'], State::Invalid, 'notification_invalid_domain'],
    'ip binding' => [['license_domain' => '', 'license_ip' => '10.0.0.5'], State::Invalid, 'notification_invalid_domain'],
    'other product' => [['product_id' => 8], State::Invalid, 'notification_invalid_product'],
    'other machine' => [['license_machine_id' => 'nope'], State::Invalid, 'notification_invalid_machine'],
]);

it('refuses a file whose payload was edited after signing', function () {
    $license = ($this->license)();
    $license->install('a@b.test', 'CODE-1');
    $file = json_decode((string) file_get_contents($this->file), true);
    file_put_contents($this->file, json_encode(['license' => str_replace('"product_id":7', '"product_id":8', $file['license'])] + $file));

    expect($license->verify(force: true)->state)->toBe(State::Invalid);
});

it('accepts comma separated domains', function () {
    $this->billing->writeLicenseFile($this->file, 7, ['license_domain' => 'other.test, www.app.test']);

    expect(($this->license)()->install('', 'CODE-1')->ok())->toBeTrue();
});

it('rebuilds a missing cache row from the file', function () {
    $license = ($this->license)();
    expect($license->verify()->ok())->toBeTrue()->and($this->store->get(''))->not->toBeNull();
});

it('reads entitlements signed inside the file', function () {
    $this->billing->writeLicenseFile($this->file, 7, ['entitlements' => ['features' => ['sla'], 'limits' => ['agents' => 3]]]);
    $status = ($this->license)()->install('', 'CODE-1');

    expect($status->allows('sla'))->toBeTrue()
        ->and($status->allows('reports'))->toBeFalse()
        ->and($status->limit('agents'))->toBe(3)
        ->and($status->entitlements->expired('updates'))->toBeTrue(); // legacy dates still apply
});

it('refuses web requests from a host that is not licensed', function () {
    ($this->license)()->install('', 'CODE-1');
    [$_SERVER['HTTP_HOST'], $_SERVER['SCRIPT_NAME']] = ['evil-app.test', '/index.php'];
    expect(($this->license)()->verify()->state)->toBe(State::Tampered);

    $_SERVER['HTTP_HOST'] = 'www.app.test';
    expect(($this->license)()->verify()->ok())->toBeTrue();
});

it('reports configuration errors without touching the network', function () {
    $status = ($this->license)(['salt' => 'short', 'productId' => 0, 'publicKey' => null])->verify();

    expect($status->state)->toBe(State::Misconfigured)
        ->and($status->errors)->toContain('config_invalid_salt', 'config_invalid_product_id', 'config_invalid_public_key')
        ->and($this->billing->requests)->toBe([]);
});

it('never touches the network, not even DNS, with the offline licence', function () {
    $license = ($this->license)(['rootIp' => '198.51.100.1', 'nameservers' => ['ns1.example.com']]);

    expect($license->install('', 'CODE-1')->ok())->toBeTrue()
        ->and($license->verify(force: true)->ok())->toBeTrue()
        ->and($this->billing->requests)->toBe([]);
});
