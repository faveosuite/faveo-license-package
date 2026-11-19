<?php

declare(strict_types=1);

use Faveo\LicenseClient\License;
use Faveo\LicenseClient\State;
use Faveo\LicenseClient\Store\FileStore;
use Faveo\LicenseClient\Tests\Support\FakeBilling;

// Billing's signed record: renew_after / valid_until / version / kid decide renewal, outages,
// replays and key changes; CLOUD mode only ever stores what billing pushes.

const H = 3600;

beforeEach(function () {
    $this->billing = new FakeBilling;
    $this->store = new FileStore(tempDir().'/cache.json');
    $this->version = 1;
    $this->record = fn (): array => [
        'v' => 1, 'features' => ['sla'], 'version' => $this->version,
        'renew_after' => gmdate(DATE_ATOM, $this->billing->now + 24 * H),
        'valid_until' => gmdate(DATE_ATOM, $this->billing->now + 30 * 24 * H),
        'kid' => substr(hash('sha256', $this->billing->publicKey), 0, 16),
    ];
    $reply = fn (array $f) => $this->billing->licenceReply($f, 'notification_license_ok', $this->billing->entitlements($f, ($this->record)()));
    $this->billing->on('/api/licenseInstall', $reply);
    $this->billing->on('/api/licenseVerify', $reply);
    $this->license = fn (array $over = []): License => new License(...$over + [
        'serverUrl' => 'https://billing.test',
        'productId' => 7,
        'salt' => 'a-long-random-salt-value',
        'store' => $this->store,
        'mode' => License::DATABASE,
        'publicKey' => $this->billing->publicKey,
        'rootUrl' => 'https://app.test',
        'days' => 5,
        'graceDays' => 7,
        'network' => $this->billing,
        'clock' => $this->billing->clock(),
    ]);
    $this->calls = fn (): int => count(array_filter($this->billing->requests, fn ($r) => str_ends_with($r['url'], '/api/licenseVerify')));
});

it("renews when billing's record says, not after `days`", function () {
    $license = ($this->license)();
    $license->install('a@b.test', 'CODE-1');

    $this->billing->now += 23 * H;
    expect($license->verify()->cached)->toBeTrue()->and(($this->calls)())->toBe(0);

    $this->billing->now += 2 * H; // past renew_after (1 day), well before `days` (5)
    expect($license->verify()->cached)->toBeFalse()->and(($this->calls)())->toBe(1);
});

it('keeps working until valid_until while billing is down, then stops', function () {
    $license = ($this->license)();
    $license->install('a@b.test', 'CODE-1');
    $validUntil = $this->billing->now + 30 * 24 * H;
    $this->billing->down = true;

    $this->billing->now += 2 * 24 * H;
    $status = $license->verify();
    expect($status->state)->toBe(State::Grace)
        ->and($status->graceEndsAt?->getTimestamp())->toBe($validUntil) // the record, not grace_days (7)
        ->and($status->allows('sla'))->toBeTrue();

    $this->billing->now = $validUntil + 1;
    expect($license->verify(force: true)->state)->toBe(State::Unreachable);
});

it('never takes a record older than the one it holds', function () {
    $this->version = 5;
    $license = ($this->license)();
    $license->install('a@b.test', 'CODE-1');

    $this->version = 4; // a replayed or late answer
    $this->billing->now += 25 * H;
    expect($license->verify()->state)->toBe(State::Grace);

    $this->version = 6;
    $this->billing->now += 2 * H;
    expect($license->verify(force: true)->state)->toBe(State::Active);
});

it('refresh() asks billing now, but at most once a minute', function () {
    $license = ($this->license)();
    $license->install('a@b.test', 'CODE-1');

    expect($license->refresh()->cached)->toBeFalse()->and(($this->calls)())->toBe(1);

    $this->billing->now += 30;
    expect($license->refresh()->cached)->toBeTrue()->and(($this->calls)())->toBe(1);

    $this->billing->now += 31;
    expect($license->refresh()->cached)->toBeFalse()->and(($this->calls)())->toBe(2);
});

it('accepts a record signed by a previous key named by its kid, and refuses an unknown kid', function () {
    $old = new FakeBilling;
    $this->billing->on('/api/licenseInstall', function (array $f) use ($old) {
        $json = (string) json_encode(['v' => 1, 'features' => ['sla'], 'kid' => substr(hash('sha256', $old->publicKey), 0, 16), 'issued_at' => gmdate(DATE_ATOM, $this->billing->now)]);

        return $this->billing->licenceReply($f, 'notification_license_ok', [
            'entitlements' => $json,
            'entitlements_signature' => $old->sign($f['product_id'].'|'.$f['license_code'].'|'.$f['root_url'].'|'.$json),
        ]);
    });

    expect(($this->license)(['previousPublicKeys' => [$old->publicKey]])->install('a@b.test', 'CODE-1')->ok())->toBeTrue();

    $this->store->delete('');
    expect(($this->license)()->install('a@b.test', 'CODE-1')->ok())->toBeFalse(); // old key no longer listed
});

it('CLOUD mode stores pushed records, never calls billing, and keeps the newest', function () {
    $cloud = fn () => ($this->license)(['mode' => License::CLOUD, 'serverUrl' => '']);
    $push = function (int $version, array $features) use ($cloud) {
        $json = (string) json_encode(['v' => 1, 'features' => $features, 'version' => $version]);

        return $cloud()->accept('CODE-1', 'https://app.test', $json, $this->billing->sign('7|CODE-1|https://app.test|'.$json));
    };

    expect($cloud()->verify()->state)->toBe(State::NotInstalled);
    expect($push(2, ['sla'])->ok())->toBeTrue();
    expect($cloud()->verify()->allows('sla'))->toBeTrue();

    $push(1, ['reports']); // arrived late: ignored
    expect($cloud()->verify()->allows('sla'))->toBeTrue()->and($cloud()->verify()->allows('reports'))->toBeFalse();

    $push(3, ['reports']);
    expect($cloud()->verify()->allows('reports'))->toBeTrue();

    expect($cloud()->accept('CODE-1', 'https://app.test', '{"v":1}', 'forged')->state)->toBe(State::Invalid);
    $this->billing->now += 400 * 24 * H;
    expect($cloud()->verify()->state)->toBe(State::Active) // nothing to renew
        ->and($this->billing->requests)->toBe([]);
});
