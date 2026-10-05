<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Http;

/** @internal */
final class Response
{
    /** @param  array<string, string>  $headers  lower-cased names */
    public function __construct(
        public readonly array $headers,
        public readonly string $body = '',
        public readonly string $error = '',
    ) {}

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function failed(): bool
    {
        return $this->error !== '';
    }
}
