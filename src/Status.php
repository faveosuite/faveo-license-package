<?php

declare(strict_types=1);

namespace Faveo\LicenseClient;

use DateTimeImmutable;

/**
 * Result of License::install() / verify(). Check $state in code; show a message looked up by $case.
 */
final class Status
{
    /**
     * @param  string  $case  stable notification_* / config_* code (translation key)
     * @param  list<string>  $errors  config_* / input_* codes when state is Misconfigured or input was refused
     * @param  string  $serverText  billing's own human text, if it sent one
     * @param  array<mixed>  $data  extra payload (licence file fields, server data)
     */
    public function __construct(
        public readonly State $state,
        public readonly string $case,
        public readonly Entitlements $entitlements,
        public readonly ?DateTimeImmutable $checkedAt = null,
        public readonly ?DateTimeImmutable $graceEndsAt = null,
        public readonly bool $graceStarted = false,   // true only on the check that entered grace
        public readonly bool $cached = false,         // answered from the cache row, no full check
        public readonly array $errors = [],
        public readonly string $serverText = '',
        public readonly array $data = [],
    ) {}

    public function ok(): bool
    {
        return $this->state->ok();
    }

    /**
     * True when the fix is a new licence file for this installation (offline licence): not
     * activated yet, the server changed, or the domain changed. Show the activation code then.
     */
    public function needsActivation(): bool
    {
        return $this->state === State::NotInstalled
            || in_array($this->case, ['notification_invalid_machine', 'notification_invalid_domain'], true);
    }

    public function allows(string $feature): bool
    {
        return $this->ok() && $this->entitlements->allows($feature);
    }

    /** 0 when the licence is not valid, null = unlimited. */
    public function limit(string $name): ?int
    {
        return $this->ok() ? $this->entitlements->limit($name) : 0;
    }

    public function withinLimit(string $name, int $count): bool
    {
        $limit = $this->limit($name);

        return $limit === null || $count <= $limit;
    }

    public function expiresAt(string $name): ?DateTimeImmutable
    {
        return $this->entitlements->expiresAt($name);
    }
}
