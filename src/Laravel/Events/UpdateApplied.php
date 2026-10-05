<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel\Events;

/** Files, migrations and caches are done. */
final class UpdateApplied
{
    public function __construct(
        public readonly string $version,
    ) {}
}
