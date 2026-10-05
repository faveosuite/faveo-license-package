<?php

declare(strict_types=1);

namespace Faveo\LicenseClient;

/** Outcome of a licence check. Only Active and Grace let the application run. */
enum State: string
{
    case Active = 'active';
    case Grace = 'grace';                 // billing unreachable, still inside grace_days
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Invalid = 'invalid';             // wrong domain / machine / product, or refused input
    case NotInstalled = 'not_installed';
    case Tampered = 'tampered';           // cache row seal or date check failed
    case Unreachable = 'unreachable';     // billing unreachable and grace used up (or disabled)
    case Misconfigured = 'misconfigured';

    public function ok(): bool
    {
        return $this === self::Active || $this === self::Grace;
    }
}
