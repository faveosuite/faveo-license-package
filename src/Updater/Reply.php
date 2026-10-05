<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Updater;

/** Billing's signed answer to an update API call. */
final class Reply
{
    /** @param  array<mixed>  $data */
    public function __construct(
        public readonly string $case,
        public readonly string $text,
        public readonly array $data,
    ) {}

    /** Billing answered with notification_operation_ok. */
    public function ok(): bool
    {
        return $this->case === 'notification_operation_ok';
    }
}
