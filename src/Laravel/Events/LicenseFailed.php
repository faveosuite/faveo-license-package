<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel\Events;

use Faveo\LicenseClient\Status;

/** A licence check returned a state that blocks the app. */
final class LicenseFailed
{
    public function __construct(
        public readonly string $addon,
        public readonly Status $status,
    ) {}
}
