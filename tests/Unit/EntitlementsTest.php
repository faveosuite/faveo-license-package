<?php

declare(strict_types=1);

use Faveo\LicenseClient\Entitlements;

it('allows everything when billing sent no entitlements', function () {
    $e = Entitlements::unrestricted(['updates' => '2000-01-01', 'support' => '0000-00-00']);

    expect($e->present())->toBeFalse()
        ->and($e->allows('anything'))->toBeTrue()
        ->and($e->limit('agents'))->toBeNull()
        ->and($e->expired('updates'))->toBeTrue()
        ->and($e->expiresAt('support'))->toBeNull();
});

it('denies by default once billing sends a block', function () {
    $e = Entitlements::fromArray(['features' => ['sla'], 'limits' => ['agents' => 25, 'departments' => null]]);

    expect($e?->allows('sla'))->toBeTrue()
        ->and($e?->allows('reports'))->toBeFalse()
        ->and($e?->limit('agents'))->toBe(25)
        ->and($e?->limit('departments'))->toBeNull()
        ->and($e?->limit('unknown'))->toBe(0)
        ->and($e?->withinLimit('agents', 25))->toBeTrue()
        ->and($e?->withinLimit('agents', 26))->toBeFalse();
});

it('rejects malformed blocks instead of treating them as unrestricted', function (array $block) {
    expect(Entitlements::fromArray($block))->toBeNull();
})->with([
    'features not a list' => [['features' => 'sla']],
    'non-string feature' => [['features' => [1]]],
    'limit not int' => [['limits' => ['agents' => '25']]],
    'bad issued_at' => [['issued_at' => 'not a date']],
]);

it('round-trips through the cache row', function () {
    $e = Entitlements::fromArray(['features' => ['sla'], 'limits' => ['agents' => 5], 'expires' => ['updates' => '2030-01-01'], 'issued_at' => '2026-09-25T10:00:00Z']);
    $back = Entitlements::fromCache((array) $e?->toArray());

    expect($back->toArray())->toBe($e?->toArray())
        ->and(Entitlements::fromCache(Entitlements::unrestricted()->toArray())->present())->toBeFalse();
});
