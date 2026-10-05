<?php

declare(strict_types=1);

use Faveo\LicenseClient\Http\Response;
use Faveo\LicenseClient\License;
use Faveo\LicenseClient\State;
use Faveo\LicenseClient\Store\FileStore;
use Faveo\LicenseClient\Tests\Support\FakeBilling;

const DAY = 86400;

beforeEach(function () {
    $this->billing = new FakeBilling;
    $this->store = new FileStore(tempDir().'/cache.json');
    $this->reply = fn (array $f) => $this->billing->licenceReply($f, 'notification_license_ok');
    $this->billing->on('/api/licenseInstall', fn (array $f) => ($this->reply)($f));
    $this->billing->on('/api/licenseVerify', fn (array $f) => ($this->reply)($f));
    $this->license = fn (array $over = []): License => new License(...$over + [
        'serverUrl' => 'https://billing.test',
        'productId' => 7,
        'salt' => 'a-long-random-salt-value',
        'store' => $this->store,
        'mode' => License::DATABASE,
        'publicKey' => $this->billing->publicKey,
        'rootUrl' => 'https://app.test',
        'network' => $this->billing,
        'clock' => $this->billing->clock(),
    ]);
    $this->verifyCalls = fn (): int => count(array_filter($this->billing->requests, fn ($r) => str_ends_with($r['url'], '/api/licenseVerify')));
});

it('installs online and verifies again only after `days`', function () {
    $license = ($this->license)();
    expect($license->install('a@b.test', 'CODE-1')->ok())->toBeTrue();

    $this->billing->now += 4 * DAY;
    expect($license->verify()->cached)->toBeTrue()->and(($this->verifyCalls)())->toBe(0);

    $this->billing->now += 2 * DAY;
    expect($license->verify()->cached)->toBeFalse()->and(($this->verifyCalls)())->toBe(1);
});

it('refuses a reply that is not signed by billing', function () {
    $this->reply = fn (array $f) => new Response(['notification_case' => 'notification_license_ok', 'notification_server_signature' => 'forged']);

    expect(($this->license)()->install('a@b.test', 'CODE-1')->case)->toBe('notification_invalid_response');
});

it('keeps working through an outage for grace_days, retrying at most every retry_minutes', function () {
    $license = ($this->license)();
    $license->install('a@b.test', 'CODE-1');
    $this->billing->now += 6 * DAY;
    $this->billing->down = true;

    $first = $license->verify();
    expect($first->state)->toBe(State::Grace)
        ->and($first->graceStarted)->toBeTrue()
        ->and($first->graceEndsAt?->getTimestamp())->toBe($this->billing->now + 7 * DAY);

    $calls = count($this->billing->requests);
    $this->billing->now += 30 * 60;
    $again = $license->verify();
    expect($again->state)->toBe(State::Grace)
        ->and($again->graceStarted)->toBeFalse()
        ->and(count($this->billing->requests))->toBe($calls); // throttled: no request

    $this->billing->now += 31 * 60;
    expect($license->verify()->state)->toBe(State::Grace)
        ->and(count($this->billing->requests))->toBe($calls + 1);

    $this->billing->now += 7 * DAY;
    expect($license->verify()->state)->toBe(State::Unreachable);

    $this->billing->down = false;
    expect($license->verify()->state)->toBe(State::Active);
});

it('never covers a definite refusal with grace, and caches it', function () {
    $license = ($this->license)();
    $license->install('a@b.test', 'CODE-1');
    $this->reply = fn (array $f) => $this->billing->licenceReply($f, 'notification_license_suspended');

    expect($license->verify(force: true)->state)->toBe(State::Suspended);
    $calls = ($this->verifyCalls)();
    expect($license->verify()->state)->toBe(State::Suspended)->and(($this->verifyCalls)())->toBe($calls);

    $this->reply = fn (array $f) => $this->billing->licenceReply($f, 'notification_license_ok');
    $this->billing->now += 61 * 60;
    expect($license->verify()->state)->toBe(State::Active);
});

it('grace can be switched off', function () {
    $license = ($this->license)(['graceDays' => 0]);
    $license->install('a@b.test', 'CODE-1');
    $this->billing->down = true;

    expect($license->verify(force: true)->state)->toBe(State::Unreachable);
});

it('trusts only entitlements signed by billing for this licence', function () {
    $this->reply = fn (array $f) => $this->billing->licenceReply($f, 'notification_license_ok', $this->billing->entitlements($f, ['features' => ['sla'], 'limits' => ['agents' => 10]]));
    $license = ($this->license)();
    $status = $license->install('a@b.test', 'CODE-1');
    expect($status->allows('sla'))->toBeTrue()->and($status->limit('agents'))->toBe(10);

    // cached entitlements survive into grace
    $this->billing->down = true;
    expect($license->verify(force: true)->allows('sla'))->toBeTrue();
});

it('treats tampered or replayed entitlements as an invalid response', function (Closure $tamper) {
    $license = ($this->license)();
    $license->install('a@b.test', 'CODE-1');
    $this->reply = function (array $f) use ($tamper) {
        return $this->billing->licenceReply($f, 'notification_license_ok', $tamper($this->billing->entitlements($f, ['features' => ['sla']]), $f));
    };

    $status = $license->verify(force: true);
    expect($status->state)->toBe(State::Grace)->and($status->allows('sla'))->toBeTrue(); // but only what was cached: legacy = all
    expect($status->entitlements->present())->toBeFalse();
})->with([
    'edited block' => [fn (array $e) => ['entitlements' => str_replace('sla', 'everything', $e['entitlements'])] + $e],
    'no signature' => [fn (array $e) => ['entitlements' => $e['entitlements']]],
    'other licence' => [fn (array $e, array $f) => (new FakeBilling)->entitlements(['license_code' => 'OTHER'] + $f, ['features' => ['sla']])],
]);

it('refuses a stale (replayed) block even with a valid signature', function () {
    $license = ($this->license)();
    $license->install('a@b.test', 'CODE-1');
    $this->reply = function (array $f) {
        $now = $this->billing->now;
        $this->billing->now -= 3 * DAY;
        $block = $this->billing->entitlements($f, ['features' => ['everything']]);
        $this->billing->now = $now;

        return $this->billing->licenceReply($f, 'notification_license_ok', $block);
    };

    expect($license->verify(force: true)->state)->toBe(State::Grace);
});

it('sends the add-on name on install and keeps add-on rows apart', function () {
    $addon = ($this->license)(['pluginName' => 'Calendar', 'productId' => 12]);
    $addon->install('a@b.test', 'CAL-1');

    expect($this->billing->requests[0]['fields']['isPlugin'])->toBe('Calendar')
        ->and($this->store->get('Calendar'))->not->toBeNull()
        ->and($this->store->get(''))->toBeNull();
});

it('reads the expiry dates billing sends when it sends no entitlements', function () {
    $this->reply = fn (array $f) => $this->billing->licenceReply($f, 'notification_license_ok', [
        'license_expire_date' => null,
        'license_updates_date' => '2026-08-31 00:00:00',
        'license_support_date' => '2026-07-31 00:00:00',
    ]);

    $status = ($this->license)()->install('a@b.test', 'CODE-1');

    expect($status->expiresAt('updates')?->format('Y-m-d'))->toBe('2026-08-31')
        ->and($status->expiresAt('support')?->format('Y-m-d'))->toBe('2026-07-31')
        ->and($status->expiresAt('license'))->toBeNull();
});
