<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Http;

use CurlHandle;

/** @internal */
final class CurlNetwork implements Network
{
    public function post(string $url, array $fields, int $timeout, bool $verifySsl, ?string $referer = null, ?string $saveTo = null): Response
    {
        $out = null;

        if ($saveTo !== null && ($out = @fopen($saveTo, 'wb')) === false) {
            return new Response([], '', "Can't write $saveTo");
        }

        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_USERAGENT => 'phpmillion cURL', // billing's API expects this UA
            CURLOPT_CONNECTTIMEOUT => min($timeout, 30),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_HEADERFUNCTION => function (CurlHandle $ch, string $line) use (&$headers): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = []; // a redirect started a new response: keep only the final headers
                } elseif (count($parts = explode(':', $line, 2)) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        $referer = $referer ?: $url;
        $referer !== '' && curl_setopt($ch, CURLOPT_REFERER, $referer);
        $out ? curl_setopt($ch, CURLOPT_FILE, $out) : curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        $out && fclose($out);

        /** @var array<string, string> $headers */
        return new Response($headers, is_string($body) ? $body : '', $error);
    }

    public function ips(string $host): array
    {
        return gethostbynamel($host) ?: [];
    }
}
