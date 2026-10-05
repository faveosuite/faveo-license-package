<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel\Events;

use Faveo\LicenseClient\Status;

/** Billing became unreachable and the grace period started. Fired once per outage. */
final class GraceStarted
{
    public function __construct(
        public readonly string $addon,
        public readonly Status $status,
    ) {}
}
