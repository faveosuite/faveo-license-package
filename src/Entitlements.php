<?php

declare(strict_types=1);

namespace Faveo\LicenseClient;

use DateTimeImmutable;
use Throwable;

/**
 * Features, limits and expiry dates granted by a licence.
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
     */
    private function __construct(
        private readonly bool $present,
        private readonly array $features,
        private readonly array $limits,
        private readonly array $expires,
        public readonly ?DateTimeImmutable $issuedAt = null,
    ) {}

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
     * Build from billing's block: {features: [...], limits: {...}, expires: {...}, issued_at: "..."}.
     * Returns null when the shape is wrong, so a malformed block is never read as "unrestricted".
     *
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $features = $data['features'] ?? [];
        $limits = $data['limits'] ?? [];
        $expires = $data['expires'] ?? [];

        if (! is_array($features) || ! is_array($limits) || ! is_array($expires)) {
            return null;
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

        $issuedAt = null;

        if (isset($data['issued_at'])) {
            try {
                $issuedAt = new DateTimeImmutable((string) $data['issued_at']);
            } catch (Throwable) {
                return null;
            }
        }

        /** @var array<string, int|null> $limits */
        return new self(true, array_values(array_unique($features)), $limits, self::dates($expires), $issuedAt);
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

    /** @return array{present: bool, features: list<string>, limits: array<string, int|null>, expires: array<string, string>, issued_at: string|null} */
    public function toArray(): array
    {
        return [
            'present' => $this->present,
            'features' => $this->features,
            'limits' => $this->limits,
            'expires' => $this->expires,
            'issued_at' => $this->issuedAt?->format(DATE_ATOM),
        ];
    }

    /** True when billing sent an entitlements block (deny-by-default applies). */
    public function present(): bool
    {
        return $this->present;
    }

    public function allows(string $feature): bool
    {
        return ! $this->present || in_array($feature, $this->features, true);
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
