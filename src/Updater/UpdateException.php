<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Updater;

use RuntimeException;
use Throwable;

/** Any update failure. $case is a stable code (translation key); $rolledBack says whether files were restored. */
final class UpdateException extends RuntimeException
{
    public function __construct(
        public readonly string $case,
        string $message = '',
        public readonly bool $rolledBack = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $case, 0, $previous);
    }

    public function withRollback(): self
    {
        return new self($this->case, $this->getMessage(), true, $this->getPrevious());
    }
}
