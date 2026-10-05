<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Updater;

use DateTimeImmutable;
use ZipArchive;

/**
 * An offline update file from billing: one zip holding
 *
 *   update.zip     the normal (stamped) update archive
 *   manifest.json  {v, product_id, version, released_at, license_code, sha256}
 *   manifest.sig   base64 Ed25519 over the exact bytes of manifest.json
 *
 * open() verifies the signature and the archive checksum; the policy checks (product, licence,
 * update period, downgrade) are Updater::applyBundle()'s. The inner archive is copied to a temp
 * file that is deleted with this object.
 */
final class Bundle
{
    private function __construct(
        public readonly int $productId,
        public readonly string $version,
        public readonly DateTimeImmutable $releasedAt,
        public readonly string $licenseCode,
        public readonly string $archivePath,
    ) {}

    public function __destruct()
    {
        @unlink($this->archivePath);
    }

    /** @throws UpdateException */
    public static function open(string $path, string $publicKey, string $tempDir): self
    {
        $invalid = new UpdateException('bundle_invalid', 'This is not an update file from billing.');
        $zip = new ZipArchive;

        if (! is_file($path) || $zip->open($path) !== true) {
            throw $invalid;
        }

        try {
            $manifestJson = $zip->getFromName('manifest.json');
            $signature = base64_decode((string) $zip->getFromName('manifest.sig'), true);
            $key = base64_decode($publicKey, true);

            if (! is_string($manifestJson) || $zip->locateName('update.zip') === false) {
                throw $invalid;
            }

            if (! is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
                || ! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || ! sodium_crypto_sign_verify_detached($signature, $manifestJson, $key)) {
                throw new UpdateException('bundle_signature_invalid', 'The update file is not signed by billing, or was changed after signing.');
            }

            $m = self::manifest($manifestJson) ?? throw $invalid;
            $archivePath = self::copyArchive($zip, $tempDir);
        } finally {
            $zip->close();
        }

        if (! hash_equals($m['sha256'], (string) hash_file('sha256', $archivePath))) {
            @unlink($archivePath);

            throw new UpdateException('archive_checksum_mismatch', 'The update file is corrupt (checksum mismatch).');
        }

        return new self($m['product_id'], $m['version'], $m['released_at'], $m['license_code'], $archivePath);
    }

    /** @return array{product_id: int, version: string, released_at: DateTimeImmutable, license_code: string, sha256: string}|null */
    private static function manifest(string $json): ?array
    {
        $m = json_decode($json, true);

        if (! is_array($m) || ($m['v'] ?? null) !== 1
            || ! is_int($m['product_id'] ?? null)
            || ! is_string($m['version'] ?? null) || $m['version'] === ''
            || ! is_string($m['license_code'] ?? null) || $m['license_code'] === ''
            || ! is_string($m['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/', $m['sha256']) !== 1
            || ! is_string($m['released_at'] ?? null)
            || ! ($released = DateTimeImmutable::createFromFormat('!Y-m-d', $m['released_at']))) {
            return null;
        }

        return [
            'product_id' => $m['product_id'],
            'version' => $m['version'],
            'released_at' => $released,
            'license_code' => $m['license_code'],
            'sha256' => $m['sha256'],
        ];
    }

    /** @throws UpdateException */
    private static function copyArchive(ZipArchive $zip, string $tempDir): string
    {
        $path = rtrim($tempDir, '/\\').'/upd_'.bin2hex(random_bytes(8)).'.zip';
        $out = @fopen($path, 'x'); // exclusive create: never follow a planted file or symlink
        $in = $zip->getStream('update.zip');

        if ($out === false || $in === false) {
            if ($out !== false) {
                fclose($out);
                @unlink($path); // ours; if fopen failed the path may be someone else's, leave it
            }

            throw new UpdateException('download_failed', "Can't unpack the update file into $tempDir.");
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        return $path;
    }
}
