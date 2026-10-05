<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel\Events;

use Faveo\LicenseClient\Status;

/** A full (non-cached) licence check finished. $addon is '' for the main product. */
final class LicenseChecked
{
    public function __construct(
        public readonly string $addon,
        public readonly Status $status,
    ) {}
}
