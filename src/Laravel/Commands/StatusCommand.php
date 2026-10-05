<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel\Commands;

use DateTimeImmutable;
use Faveo\LicenseClient\Laravel\LicenseManager;
use Faveo\LicenseClient\State;
use Illuminate\Console\Command;

final class StatusCommand extends Command
{
    protected $signature = 'license:status
        {--addon= : Add-on name from license-client.addons}
        {--force : Do a full check instead of reading the cache}';

    protected $description = 'Show the licence state, entitlements and expiry dates';

    public function handle(LicenseManager $licenses): int
    {
        $addon = is_string($addon = $this->option('addon')) ? $addon : '';
        $status = $licenses->verify((bool) $this->option('force'), $addon);
        $e = $status->entitlements;
        $date = fn (?DateTimeImmutable $d): string => $d?->format('Y-m-d H:i') ?? '—';

        $section = function (string $title): void {
            $this->newLine();
            $this->line("  <fg=cyan;options=bold>$title</>");
        };

        $section('Setup');
        $this->components->twoColumnDetail('Identity file', $this->identity());
        $this->components->twoColumnDetail('Plugins found', $this->plugins());

        $section('Licence');
        $color = match (true) {
            $status->ok() => 'green',
            $status->state === State::Grace => 'yellow',
            default => 'red',
        };
        $this->components->twoColumnDetail('State', "<fg=$color;options=bold>● {$status->state->value}</>");
        $this->components->twoColumnDetail('Message', $licenses->message($status));
        $this->components->twoColumnDetail('Last full check', $date($status->checkedAt));
        $status->graceEndsAt && $this->components->twoColumnDetail('Grace ends', $date($status->graceEndsAt));
        $section('Entitlements');
        $this->components->twoColumnDetail('Source', $e->present() ? 'from billing' : 'not sent by billing (all allowed)');

        if ($e->present()) {
            $this->components->twoColumnDetail('Features', implode(', ', $e->features()) ?: '—');
            foreach ($e->limits() as $name => $limit) {
                $this->components->twoColumnDetail("Limit: $name", (string) ($limit ?? 'unlimited'));
            }
        }

        $section('Expiry');
        foreach (['license', 'updates', 'support'] as $name) {
            $this->components->twoColumnDetail(ucfirst($name), $date($e->expiresAt($name)));
        }
        $this->newLine();

        return $status->ok() ? self::SUCCESS : self::FAILURE;
    }

    private function identity(): string
    {
        /** @var array{file: string|null, state: string, used: list<string>} $identity */
        $identity = (array) config('license-client.identity', ['file' => null, 'state' => 'not_set', 'used' => []]);

        return match ($identity['state']) {
            'loaded' => $identity['file'].($identity['used'] ? ' (used: '.implode(', ', $identity['used']).')' : ' (all values set explicitly)'),
            'missing' => $identity['file'].' — not found, using config/.env',
            'unreadable' => $identity['file'].' — not valid JSON, using config/.env',
            default => 'not set (license-client.identity_file), using config/.env',
        };
    }

    private function plugins(): string
    {
        /** @var array{pattern: string|null, found: array<string, string>, invalid: list<string>} $discovered */
        $discovered = (array) config('license-client.addons_discovered', ['pattern' => null, 'found' => [], 'invalid' => []]);

        if (! $discovered['pattern']) {
            return 'discovery off (license-client.addons_discover)';
        }
        $text = $discovered['found'] ? implode(', ', array_keys($discovered['found'])) : 'none';

        return $discovered['invalid'] ? $text.' — ignored invalid: '.implode(', ', $discovered['invalid']) : $text;
    }
}
