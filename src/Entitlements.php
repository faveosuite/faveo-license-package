<?php

declare(strict_types=1);

namespace Faveo\LicenseClient;

use DateTimeImmutable;
use Throwable;

/**
 * Features, limits, chosen values and expiry dates granted by a licence.
 *
 * When billing sends an `entitlements` block access is deny-by-default: unknown features are off
 * and unknown limits are 0. When it doesn't (billing not upgraded yet) everything is allowed and
 * unlimited, exactly like before entitlements existed — present() tells the two apart.
 */
final class Entitlements
{
    /**
     * @param  list<string>  $features
     * @param  array<string, int|null>  $limits  null = unlimited
     * @param  array<string, string>  $expires  name => Y-m-d
     * @param  array<string, string>  $values  select / text choices
     * @param  array<string, string>  $ends  feature => Y-m-d it stops on (a dated trial)
     */
    private function __construct(
        private readonly bool $present,
        private readonly array $features,
        private readonly array $limits,
        private readonly array $expires,
        public readonly ?DateTimeImmutable $issuedAt = null,
        private readonly array $values = [],
        private readonly array $ends = [],
        public readonly int $version = 0,                        // goes up when the licence's rights change
        public readonly ?DateTimeImmutable $renewAfter = null,   // ask billing for a fresh record after this
        public readonly ?DateTimeImmutable $validUntil = null,   // keep working without billing until this
        public readonly ?string $kid = null,                     // id of the key that signed it
    ) {}

    /** Block format this client reads. Billing bumps `v` only for a change older clients can't read. */
    public const int VERSION = 1;

    /**
     * Billing sent no entitlements: allow everything, keep whatever expiry dates are known.
     *
     * @param  array<mixed>  $expires
     */
    public static function unrestricted(array $expires = []): self
    {
        return new self(false, [], [], self::dates($expires));
    }

    /** Nothing granted (used when the licence itself is not valid). */
    public static function none(): self
    {
        return new self(true, [], [], []);
    }

    /**
     * Build from billing's block: {v, features: [...], limits: {...}, values: {...}, ends: {...},
     * expires: {...}, issued_at: "..."}. Returns null when the shape is wrong or the version is newer
     * than this client knows, so such a block is never read as "unrestricted".
     *
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $features = $data['features'] ?? [];
        $limits = $data['limits'] ?? [];
        $expires = $data['expires'] ?? [];
        $values = $data['values'] ?? [];
        $ends = $data['ends'] ?? [];

        if (($data['v'] ?? self::VERSION) !== self::VERSION
            || ! is_array($features) || ! is_array($limits) || ! is_array($expires) || ! is_array($values) || ! is_array($ends)) {
            return null;
        }
        foreach ($values as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                return null;
            }
        }
        foreach ($ends as $name => $date) {
            if (! is_string($name) || ! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                return null;
            }
        }
        foreach ($features as $feature) {
            if (! is_string($feature)) {
                return null;
            }
        }
        foreach ($limits as $name => $limit) {
            if (! is_string($name) || ! ($limit === null || is_int($limit))) {
                return null;
            }
        }

        $version = $data['version'] ?? 0;
        $kid = $data['kid'] ?? null;

        if (! is_int($version) || $version < 0 || ! ($kid === null || is_string($kid))) {
            return null;
        }

        try {
            $at = fn (string $key): ?DateTimeImmutable => isset($data[$key]) ? new DateTimeImmutable((string) $data[$key]) : null;
            [$issuedAt, $renewAfter, $validUntil] = [$at('issued_at'), $at('renew_after'), $at('valid_until')];
        } catch (Throwable) {
            return null;
        }

        /** @var array<string, int|null> $limits */
        /** @var array<string, string> $values */
        return new self(true, array_values(array_unique($features)), $limits, self::dates($expires), $issuedAt, $values, $ends, $version, $renewAfter, $validUntil, $kid);
    }

    /**
     * Inverse of toArray(), for the sealed cache row.
     *
     * @param  array<mixed>  $data
     */
    public static function fromCache(array $data): self
    {
        if (! ($data['present'] ?? false)) {
            return self::unrestricted(is_array($data['expires'] ?? null) ? $data['expires'] : []);
        }

        return self::fromArray($data) ?? self::none();
    }

    /** @return array{present: bool, features: list<string>, limits: array<string, int|null>, values: array<string, string>, ends: array<string, string>, expires: array<string, string>, issued_at: string|null, version: int, renew_after: string|null, valid_until: string|null, kid: string|null} */
    public function toArray(): array
    {
        return [
            'present' => $this->present,
            'features' => $this->features,
            'limits' => $this->limits,
            'values' => $this->values,
            'ends' => $this->ends,
            'expires' => $this->expires,
            'issued_at' => $this->issuedAt?->format(DATE_ATOM),
            'version' => $this->version,
            'renew_after' => $this->renewAfter?->format(DATE_ATOM),
            'valid_until' => $this->validUntil?->format(DATE_ATOM),
            'kid' => $this->kid,
        ];
    }

    /** True when billing sent an entitlements block (deny-by-default applies). */
    public function present(): bool
    {
        return $this->present;
    }

    /** Granted, and not past its own end date (a dated trial stops on its own, even offline). */
    public function allows(string $feature, ?DateTimeImmutable $now = null): bool
    {
        if (! $this->present) {
            return true;
        }

        if (! in_array($feature, $this->features, true)) {
            return false;
        }

        return ! isset($this->ends[$feature])
            || new DateTimeImmutable($this->ends[$feature].' 23:59:59') >= ($now ?? new DateTimeImmutable);
    }

    /** A select / text choice, e.g. value('data_region') → "eu". null = not set (use your safest default). */
    public function value(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }

    /** End date of a dated feature, or null when it lasts as long as the licence. */
    public function endsAt(string $feature): ?DateTimeImmutable
    {
        return isset($this->ends[$feature]) ? new DateTimeImmutable($this->ends[$feature].' 23:59:59') : null;
    }

    /** @return list<string> */
    public function features(): array
    {
        return $this->features;
    }

    /** @return array<string, int|null> every limit billing sent; null = unlimited */
    public function limits(): array
    {
        return $this->limits;
    }

    /** null = unlimited. */
    public function limit(string $name): ?int
    {
        if (! $this->present) {
            return null;
        }

        return array_key_exists($name, $this->limits) ? $this->limits[$name] : 0;
    }

    public function withinLimit(string $name, int $count): bool
    {
        $limit = $this->limit($name);

        return $limit === null || $count <= $limit;
    }

    /** Expiry of 'license', 'updates', 'support' (or any other name billing sends). null = never. */
    public function expiresAt(string $name): ?DateTimeImmutable
    {
        return isset($this->expires[$name]) ? new DateTimeImmutable($this->expires[$name].' 23:59:59') : null;
    }

    public function expired(string $name, ?DateTimeImmutable $now = null): bool
    {
        $at = $this->expiresAt($name);

        return $at !== null && $at < ($now ?? new DateTimeImmutable);
    }

    /**
     * Keep valid Y-m-d dates only; '0000-00-00', '--', '' and null mean "never".
     *
     * @param  array<mixed>  $expires
     * @return array<string, string>
     */
    private static function dates(array $expires): array
    {
        $out = [];
        foreach ($expires as $name => $date) {
            $day = substr((string) $date, 0, 10);

            if (is_string($name) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 && $day !== '0000-00-00') {
                $out[$name] = $day;
            }
        }

        return $out;
    }
}
