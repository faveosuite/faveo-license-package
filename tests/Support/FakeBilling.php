<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Tests\Support;

use Closure;
use Faveo\LicenseClient\Http\Network;
use Faveo\LicenseClient\Http\Response;
use Faveo\LicenseClient\MachineId;
use ZipArchive;

/**
 * In-memory billing server: signs replies exactly like the real one, so the protocol is tested for real.
 */
final class FakeBilling implements Network
{
    public const IPS = ['203.0.113.10'];

    public int $now;

    public bool $down = false;

    /** @var list<array{url: string, fields: array<string, scalar>}> */
    public array $requests = [];

    public readonly string $secretKey;

    public readonly string $publicKey;

    /** @var array<string, Closure(array<string, scalar>, ?string): Response> */
    private array $routes = [];

    public function __construct()
    {
        $keys = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keys);
        $this->publicKey = base64_encode(sodium_crypto_sign_publickey($keys));
        $this->now = time();
    }

    /** @param  Closure(array<string, scalar>, ?string): Response  $handler */
    public function on(string $path, Closure $handler): self
    {
        $this->routes[$path] = $handler;

        return $this;
    }

    public function post(string $url, array $fields, int $timeout, bool $verifySsl, ?string $referer = null, ?string $saveTo = null): Response
    {
        $this->requests[] = ['url' => $url, 'fields' => $fields];

        if ($this->down) {
            return new Response([], '', 'Could not resolve host');
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $handler = $this->routes[$path] ?? fn (): Response => new Response([], '', 'HTTP 404');

        return $handler($fields, $saveTo);
    }

    public function ips(string $host): array
    {
        return self::IPS;
    }

    public function clock(): Closure
    {
        return fn (): int => $this->now;
    }

    public function sign(string $message): string
    {
        return base64_encode(sodium_crypto_sign_detached($message, $this->secretKey));
    }

    /**
     * A licence API reply signed like billing does.
     *
     * @param  array<string, scalar>  $fields  the request
     * @param  array<mixed>  $data
     */
    public function licenceReply(array $fields, string $case, array $data = [], string $text = ''): Response
    {
        $sig = hash('sha256', implode('', self::IPS).$fields['product_id'].$fields['license_code'].$fields['client_email'].$fields['root_url'].gmdate('Y-m-d', $this->now));

        return new Response([
            'notification_case' => $case,
            'notification_text' => $text,
            'notification_data' => (string) json_encode($data),
            'notification_server_signature' => $sig,
        ]);
    }

    /**
     * Signed entitlements for notification_data, as billing sends them in DATABASE mode.
     *
     * @param  array<string, scalar>  $fields
     * @param  array<mixed>  $entitlements
     * @return array{entitlements: string, entitlements_signature: string}
     */
    public function entitlements(array $fields, array $entitlements): array
    {
        $json = (string) json_encode($entitlements + ['issued_at' => gmdate(DATE_ATOM, $this->now)]);

        return [
            'entitlements' => $json,
            'entitlements_signature' => $this->sign($fields['product_id'].'|'.$fields['license_code'].'|'.$fields['root_url'].'|'.$json),
        ];
    }

    /**
     * An update API reply signed like billing does.
     *
     * @param  array<string, string>  $headers
     */
    public function updateReply(string $productKey, int $productId, string $case = '', string $body = '', array $headers = [], array $data = []): Response
    {
        return new Response($headers + [
            'notification_case' => $case,
            'notification_text' => '',
            'notification_data' => (string) json_encode($data),
            'notification_server_signature' => hash('sha256', implode('', self::IPS).$productKey.$productId.gmdate('Y-m-d')),
        ], $body);
    }

    /**
     * Write a signed license.json like billing's LicenseFileService.
     *
     * @param  array<string, mixed>  $over
     */
    public function writeLicenseFile(string $path, int $productId, array $over = []): void
    {
        $payload = (string) json_encode($over + [
            'license_code' => 'CODE-1',
            'license_domain' => 'app.test',
            'product_id' => $productId,
            'license_status' => 1,
            'license_expire_date' => '2999-01-01 00:00:00',
            'license_updates_date' => '2000-01-01',
            'license_support_date' => null,
            'license_ip' => '',
            'license_machine_id' => MachineId::hashedId((string) $productId),
            'scheme_query' => ['product_schema' => base64_encode('CREATE TABLE x (y INT)')],
        ]);
        file_put_contents($path, json_encode(['license' => $payload, 'signature' => $this->sign($payload)]));
    }

    /**
     * Build an offline update file like billing's "Download offline update".
     *
     * @param  array<string, mixed>  $over  manifest overrides
     */
    public function makeBundle(string $path, string $updateZip, array $over = [], ?string $signWith = null): string
    {
        $manifest = (string) json_encode($over + [
            'v' => 1,
            'product_id' => 7,
            'version' => 'v2',
            'released_at' => '2026-09-01',
            'license_code' => 'CODE-1',
            'sha256' => (string) hash_file('sha256', $updateZip),
        ]);
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($updateZip, 'update.zip');
        $zip->setCompressionName('update.zip', ZipArchive::CM_STORE);
        $zip->addFromString('manifest.json', $manifest);
        $zip->addFromString('manifest.sig', $signWith ?? $this->sign($manifest));
        $zip->close();

        return $path;
    }
}
