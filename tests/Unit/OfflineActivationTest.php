<?php

declare(strict_types=1);

use Faveo\LicenseClient\License;
use Faveo\LicenseClient\MachineId;
use Faveo\LicenseClient\State;
use Faveo\LicenseClient\Store\FileStore;
use Faveo\LicenseClient\Tests\Support\FakeBilling;

beforeEach(function () {
    $this->dir = tempDir();
    $this->billing = new FakeBilling;
    $this->store = new FileStore($this->dir.'/cache.json');
    $this->file = $this->dir.'/installed/license.json';
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
    // what the customer downloads from billing
    $this->download = function (array $over = []): string {
        $path = $this->dir.'/download-'.bin2hex(random_bytes(3)).'.json';
        $this->billing->writeLicenseFile($path, 7, $over);

        return (string) file_get_contents($path);
    };
});

function decodeActivationCode(string $code): array
{
    expect($code)->toStartWith('FLC1.');

    return json_decode(base64_decode(strtr(substr($code, 5), '-_', '+/')), true);
}

it('builds an activation code billing can bind from, without any network call', function () {
    $data = decodeActivationCode(($this->license)()->activationCode(' CODE-1 '));

    expect($data)->toBe([
        'v' => 1,
        'product_id' => 7,
        'license_code' => 'CODE-1',
        'domain' => 'app.test',
        'machine_id' => MachineId::hashedId('7'),
        'addon' => null,
    ])->and($this->billing->requests)->toBe([]);
});

it('scopes add-on codes to the main product machine id', function () {
    $data = decodeActivationCode(($this->license)(['pluginName' => 'calendar', 'productId' => 12, 'machineScope' => '7'])->activationCode('CAL-1'));

    expect($data['product_id'])->toBe(12)
        ->and($data['addon'])->toBe('calendar')
        ->and($data['machine_id'])->toBe(MachineId::hashedId('7'));
});

it('refuses to build a code from bad input', function (array $over, string $code, string $error) {
    expect(fn () => ($this->license)($over)->activationCode($code))->toThrow(RuntimeException::class, $error);
})->with([
    'no code' => [[], '  ', 'input_empty_license_data'],
    'no url' => [['rootUrl' => 'not a url'], 'CODE-1', 'input_invalid_url'],
    'no product' => [['productId' => 0], 'CODE-1', 'config_invalid_product_id'],
]);

it('imports a licence file: first activation, then answers from the cache', function () {
    $license = ($this->license)();
    $status = $license->import(($this->download)());

    expect($status->state)->toBe(State::Active)
        ->and(file_get_contents($this->file))->toContain('"signature"')
        ->and($license->verify()->cached)->toBeTrue()
        ->and($license->licenseCode())->toBe('CODE-1');
});

it('never replaces a working licence with a bad upload', function (Closure $bad, State $state) {
    $license = ($this->license)();
    $license->import(($this->download)());
    $before = file_get_contents($this->file);

    $status = $license->import($bad->call($this));

    expect($status->state)->toBe($state)
        ->and(file_get_contents($this->file))->toBe($before)
        ->and($license->verify(force: true)->ok())->toBeTrue();
})->with([
    'not json' => [fn () => 'garbage', State::Invalid],
    'edited after signing' => [function () {
        $file = json_decode(($this->download)(), true);
        $file['license'] = str_replace('"license_expire_date":"2999', '"license_expire_date":"3999', $file['license']);

        return (string) json_encode($file);
    }, State::Invalid],
    'other server' => [fn () => ($this->download)(['license_machine_id' => 'another-server']), State::Invalid],
    'other domain' => [fn () => ($this->download)(['license_domain' => 'other.test']), State::Invalid],
    'expired' => [fn () => ($this->download)(['license_expire_date' => '2000-01-01']), State::Expired],
    'signed by someone else' => [function () {
        $other = new FakeBilling;
        $path = $this->dir.'/evil.json';
        $other->writeLicenseFile($path, 7);

        return (string) file_get_contents($path);
    }, State::Invalid],
]);

it('renews by importing a newer file over the old one', function () {
    $license = ($this->license)();
    $license->import(($this->download)(['license_expire_date' => '2027-01-01']));
    $status = $license->import(($this->download)(['license_expire_date' => '2028-01-01']));

    expect($status->expiresAt('license')?->format('Y-m-d'))->toBe('2028-01-01')
        ->and($license->verify(force: true)->expiresAt('license')?->format('Y-m-d'))->toBe('2028-01-01');
});

it('recovers a "server changed" licence by importing the new server\'s file', function () {
    $license = ($this->license)();
    $license->import(($this->download)());
    file_put_contents($this->file, ($this->download)(['license_machine_id' => 'old-server']));
    expect($license->verify(force: true)->case)->toBe('notification_invalid_machine');

    expect($license->import(($this->download)())->ok())->toBeTrue();
});

it('only imports in FILE mode', function () {
    expect(($this->license)(['mode' => License::DATABASE])->import(($this->download)())->case)->toBe('notification_not_file_mode');
});

it('tells the application when a new licence file is needed', function (array $over, bool $needs) {
    $license = ($this->license)();
    $license->import(($this->download)());
    file_put_contents($this->file, ($this->download)($over));

    expect($license->verify(force: true)->needsActivation())->toBe($needs);
})->with([
    'server changed' => [['license_machine_id' => 'old-server'], true],
    'domain changed' => [['license_domain' => 'old.test'], true],
    'expired' => [['license_expire_date' => '2000-01-01'], false],
    'valid' => [[], false],
]);

it('needs activation before the first licence file', function () {
    expect(($this->license)()->verify()->needsActivation())->toBeTrue();
});
