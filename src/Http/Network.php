<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Http;

/**
 * Everything the clients need from the network. Billing's signatures are bound to its resolved IPs,
 * so DNS lives here too. CurlNetwork is the real one; tests swap in a fake billing server.
 *
 * @internal
 */
interface Network
{
    /**
     * POST form fields. With $saveTo the body is streamed to that file instead of memory.
     *
     * @param  array<string, scalar>  $fields
     */
    public function post(string $url, array $fields, int $timeout, bool $verifySsl, ?string $referer = null, ?string $saveTo = null): Response;

    /** @return list<string> IPv4 addresses the host resolves to, [] when it doesn't */
    public function ips(string $host): array;
}
