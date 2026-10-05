<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel;

use Faveo\LicenseClient\Status;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Thrown by the middleware. A 403 by default; render it your own way in bootstrap/app.php. */
final class LicenseException extends HttpException
{
    public function __construct(
        public readonly Status $status,
        string $message,
        public readonly ?string $feature = null,
    ) {
        parent::__construct(403, $message);
    }
}
