<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Updater;

use Closure;
use DateTimeImmutable;
use Faveo\LicenseClient\Http\CurlNetwork;
use Faveo\LicenseClient\Http\Network;
use Faveo\LicenseClient\Http\Response;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileObject;
use Throwable;
use ZipArchive;

/**
 * Update client for Faveo billing (PHP Auto Update Script compatible API).
 *
 * apply() runs the safe pipeline: lock → download → verify signature → inspect zip → preflight →
 * backup → before hook → extract → after hook. Extraction failure restores the backup automatically.
 */
final class Updater
{
    private readonly Network $network;

    private readonly string $installDir;

    /**
     * @param  array<string, list<string>>  $replaceDirs  dirs emptied before extracting when the archive ships
     *                                                    them, value = sub-paths to keep, e.g. ['vendor' => ['acme/state']]
     */
    public function __construct(
        private readonly string $serverUrl,
        private readonly int $productId,
        private readonly string $productKey,
        string $installDir,
        private readonly string $backupDir,
        private readonly ?string $publicKey = null,          // base64 Ed25519, verifies archive signatures
        private readonly bool $requireSignedArchives = true,
        private readonly array $replaceDirs = [],
        private readonly int $maxArchiveMb = 1024,
        private readonly int $keepBackups = 2,
        private readonly int $timeout = 300,                 // ~1 second per MB of archive
        private readonly bool $verifySsl = true,
        private readonly ?string $tempDir = null,
        ?Network $network = null,
        private readonly ?string $lockFile = null,             // default <backupDir>/.lock; share one across updaters
    ) {
        $this->installDir = rtrim($installDir, '/\\');
        $this->network = $network ?? new CurlNetwork;
    }

    public function versions(): Reply
    {
        return $this->call('/api/getAllVersions');
    }

    /**
     * Public versions newer than $current on the release channel, oldest first. Same rules as
     * billing's /version/latest: 'pre_release' adds pre-releases, 'beta' adds betas and pre-releases.
     *
     * @return list<array<string, mixed>>
     *
     * @throws UpdateException
     */
    public function available(string $current, string $releaseType = 'official'): array
    {
        $channels = match ($releaseType) {
            'beta' => ['beta', 'pre_release', 'official'],
            'pre_release' => ['pre_release', 'official'],
            default => ['official'],
        };
        $plain = fn (mixed $v): string => ltrim(str_replace('_', '.', (string) $v), 'vV.');

        $newer = array_values(array_filter(
            (array) ($this->versions()->data['product_versions'] ?? []),
            fn (mixed $row): bool => is_array($row)
                && empty($row['is_private'])
                && in_array($row['release_type'] ?? 'official', $channels, true)
                && version_compare($plain($row['version'] ?? ''), $plain($current), '>'),
        ));
        usort($newer, fn (array $a, array $b): int => version_compare($plain($a['version']), $plain($b['version'])));

        return $newer;
    }

    public function version(string $version): Reply
    {
        return $this->call('/api/getVersions', ['version_number' => $version]);
    }

    /**
     * Add-ons the customer of $licenseCode can install, from billing's signed /api/licensedPlugins.
     * Each row: product_id, product_name, product_key, product_description, version, license_code,
     * path (the add-on's install folder) and dependency. Ask the main product's updater: billing
     * finds the customer from that licence, never from anything else the client sends.
     *
     * @return list<array<string, mixed>>
     *
     * @throws UpdateException when billing refuses (e.g. notification_license_not_found) or can't be reached
     */
    public function plugins(string $licenseCode): array
    {
        $reply = $this->call('/api/licensedPlugins', ['license_code' => $licenseCode]);

        if (! $reply->ok()) {
            throw new UpdateException($reply->case ?: 'notification_invalid_response', $reply->text ?: 'Billing refused the add-on list.');
        }

        return array_values(array_filter((array) ($reply->data['plugins'] ?? []), is_array(...)));
    }

    /**
     * Download, verify and install $version over installDir.
     *
     * Pass the installed $licenseCode so billing stamps the build for this customer (FILE mode installs
     * stay FILE mode). $before runs after the backup, right before files change (maintenance mode);
     * $after runs once files are in place (migrations). An $after failure does NOT roll files back —
     * the database may be half migrated — it's reported and the backup is kept for rollback().
     *
     * @param  (Closure(Backup): void)|null  $before
     * @param  (Closure(Backup): void)|null  $after
     *
     * @throws UpdateException
     */
    public function apply(string $version, ?string $licenseCode = null, ?Closure $before = null, ?Closure $after = null, string $fileType = 'version_upgrade_file'): Backup
    {
        $this->preload();
        $lock = $this->lock();
        $zip = null;

        try {
            $zip = $this->download($version, $licenseCode, $fileType);

            return $this->install($zip, $version, $before, $after);
        } finally {
            $zip !== null && @unlink($zip);
            $lock->flock(LOCK_UN);
        }
    }

    /**
     * Open and verify an offline update file uploaded by the customer (billing's signature over the
     * manifest, and the inner archive's checksum). Nothing on disk changes.
     *
     * @throws UpdateException
     */
    public function openBundle(string $path): Bundle
    {
        return Bundle::open($path, (string) $this->publicKey, $this->tempDir ?? sys_get_temp_dir());
    }

    /**
     * Install an offline update file — the same safe pipeline as apply(), without billing.
     *
     * Refuses a bundle for another product or another customer's licence (it carries that
     * customer's licence file), a version released after the update period ended, and — unless
     * $force — a downgrade.
     *
     * @param  (Closure(Backup): void)|null  $before
     * @param  (Closure(Backup): void)|null  $after
     *
     * @throws UpdateException
     */
    public function applyBundle(
        Bundle $bundle,
        ?string $licenseCode,
        ?string $currentVersion = null,
        ?DateTimeImmutable $updatesExpireAt = null,
        bool $force = false,
        ?Closure $before = null,
        ?Closure $after = null,
    ): Backup {
        if ($bundle->productId !== $this->productId) {
            throw new UpdateException('bundle_wrong_product', 'This update file is for a different product.');
        }

        if ($licenseCode === null || ! hash_equals($bundle->licenseCode, $licenseCode)) {
            throw new UpdateException('bundle_wrong_license', 'This update file was made for a different licence. Download it again from your own account.');
        }

        if ($updatesExpireAt !== null && $bundle->releasedAt > $updatesExpireAt) {
            throw new UpdateException('updates_expired', 'This version was released after your update period ended. Renew it in billing to install it.');
        }

        if (! $force && $currentVersion !== null && version_compare(ltrim($bundle->version, 'vV'), ltrim($currentVersion, 'vV'), '<')) {
            throw new UpdateException('bundle_downgrade', "This update ({$bundle->version}) is older than the installed version ({$currentVersion}).");
        }

        $this->preload();
        $lock = $this->lock();

        try {
            return $this->install($bundle->archivePath, $bundle->version, $before, $after);
        } finally {
            $lock->flock(LOCK_UN);
        }
    }

    /**
     * inspect → preflight → backup → before → extract (restore on failure) → after → prune.
     *
     * @param  (Closure(Backup): void)|null  $before
     * @param  (Closure(Backup): void)|null  $after
     *
     * @throws UpdateException
     */
    private function install(string $zip, string $version, ?Closure $before, ?Closure $after): Backup
    {
        $archive = Archive::inspect($zip, $this->maxArchiveMb * 1024 * 1024);
        $this->preflight($archive);

        $backup = Backup::create($this->backupDir, $this->installDir, $version, $archive, $this->protectedReplaceDirs());

        try {
            $before && $before($backup);

            if (! $archive->extractTo($this->installDir)) {
                throw new UpdateException('extract_failed', "Can't extract the archive into {$this->installDir}");
            }
        } catch (Throwable $e) {
            $backup->restore();
            $e = $e instanceof UpdateException ? $e : new UpdateException('extract_failed', $e->getMessage(), previous: $e);

            throw $e->withRollback();
        }

        try {
            $after && $after($backup);
        } catch (Throwable $e) {
            throw new UpdateException('post_update_failed', $e->getMessage(), previous: $e);
        }
        Backup::prune($this->backupDir, $this->keepBackups);

        return $backup;
    }

    /**
     * Restore the newest backup that hasn't been restored yet.
     *
     * @throws UpdateException
     */
    public function rollback(): Backup
    {
        $this->preload();
        $lock = $this->lock();

        try {
            foreach (Backup::all($this->backupDir) as $backup) {
                if (! $backup->restored()) {
                    $backup->restore();

                    return $backup;
                }
            }

            throw new UpdateException('no_backup', 'There is no backup to roll back to.');
        } finally {
            $lock->flock(LOCK_UN);
        }
    }

    /** @return list<Backup> newest first */
    public function backups(): array
    {
        return Backup::all($this->backupDir);
    }

    /** @return list<string> config_* codes, empty when fine */
    public function configErrors(): array
    {
        $errors = [];

        if (filter_var($this->serverUrl, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'config_invalid_server_url';
        }

        if ($this->productId < 1) {
            $errors[] = 'config_invalid_product_id';
        }

        if ($this->productKey === '' || $this->productKey === 'some_random_key') {
            $errors[] = 'config_invalid_product_key';
        }

        if (! is_dir($this->installDir) || ! is_writable($this->installDir)) {
            $errors[] = 'config_install_dir_not_writable';
        }

        if ($this->requireSignedArchives && strlen((string) base64_decode((string) $this->publicKey, true)) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            $errors[] = 'config_invalid_public_key';
        }

        if (! class_exists(ZipArchive::class)) {
            $errors[] = 'config_zip_missing';
        }

        return $errors;
    }

    /**
     * Stream the archive to a temp file and verify billing's signature over it.
     *
     * @throws UpdateException
     */
    private function download(string $version, ?string $licenseCode, string $fileType): string
    {
        $path = rtrim($this->tempDir ?? sys_get_temp_dir(), '/\\').'/upd_'.bin2hex(random_bytes(8)).'.zip';
        $handle = @fopen($path, 'x'); // exclusive create: never follow a planted file or symlink

        if ($handle === false) {
            throw new UpdateException('download_failed', "Can't create a temp file for the download.");
        }
        fclose($handle);

        try {
            $fields = ['version_number' => $version, 'file_type' => $fileType] + ($licenseCode ? ['license_code' => $licenseCode] : []);
            $reply = $this->call('/api/downloadFile', $fields, $path, $res);

            // A refusal still has a body (billing's JSON), so check the case before trusting the file.
            if (! filesize($path) || ($reply->case !== '' && ! $reply->ok())) {
                throw new UpdateException($reply->case ?: 'download_failed', $reply->text ?: 'Billing sent no archive.');
            }
            $this->verifyArchive($path, $version, $res);
        } catch (Throwable $e) {
            @unlink($path);

            throw $e;
        }

        return $path;
    }

    /**
     * billing sends archive_sha256 + archive_signature = Ed25519("product_id|version|sha256").
     *
     * @throws UpdateException
     */
    private function verifyArchive(string $path, string $version, ?Response $res): void
    {
        $sha = strtolower($res?->header('archive_sha256') ?? '');
        $sig = base64_decode($res?->header('archive_signature') ?? '', true);

        if ($sha === '' || $sig === false || $sig === '') {
            if ($this->requireSignedArchives) {
                throw new UpdateException('archive_unsigned', 'Billing sent an unsigned archive; refusing to install it.');
            }

            return; // only reachable with require_signed_archives=false, meant for the billing rollout
        }

        if (! hash_equals($sha, (string) hash_file('sha256', $path))) {
            throw new UpdateException('archive_checksum_mismatch', 'The downloaded archive is corrupt (checksum mismatch).');
        }
        $key = base64_decode((string) $this->publicKey, true);

        if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($sig, $this->productId.'|'.$version.'|'.$sha, $key)) {
            throw new UpdateException('archive_signature_invalid', 'The archive signature is invalid; refusing to install it.');
        }
    }

    /** @throws UpdateException */
    private function preflight(Archive $archive): void
    {
        $free = @disk_free_space($this->installDir);

        if ($free !== false && $free < 2 * $archive->bytes) {
            throw new UpdateException('disk_full', 'Not enough free disk space for the update and its backup.');
        }
        foreach ($archive->files as $file) {
            $target = "{$this->installDir}/$file";
            while (! file_exists($target)) {
                $target = dirname($target);
            }

            if (! is_writable($target)) {
                throw new UpdateException('not_writable', "$target is not writable.");
            }
        }
    }

    /**
     * replace_dirs plus this package's own directory, so an update never wipes the code running it.
     *
     * @return array<string, list<string>>
     */
    private function protectedReplaceDirs(): array
    {
        $dirs = $this->replaceDirs;
        $self = realpath(dirname(__DIR__, 2));
        $install = realpath($this->installDir);

        if ($self !== false && $install !== false && str_starts_with($self, $install.DIRECTORY_SEPARATOR)) {
            $relative = str_replace('\\', '/', substr($self, strlen($install) + 1));
            [$top, $rest] = array_pad(explode('/', $relative, 2), 2, '');

            if (isset($dirs[$top]) && $rest !== '' && ! in_array($rest, $dirs[$top], true)) {
                $dirs[$top][] = $rest;
            }
        }

        return $dirs;
    }

    /** Load every class the pipeline needs before vendor/ can move, so nothing depends on the autoloader. */
    private function preload(): void
    {
        foreach ([Archive::class, Backup::class, Bundle::class, UpdateException::class, Reply::class, Response::class, RecursiveIteratorIterator::class] as $class) {
            class_exists($class);
        }
    }

    /** @throws UpdateException */
    private function lock(): SplFileObject
    {
        $path = $this->lockFile ?? $this->backupDir.'/.lock';
        is_dir(dirname($path)) || @mkdir(dirname($path), 0775, true);

        try {
            $lock = new SplFileObject($path, 'c');
        } catch (RuntimeException) {
            throw new UpdateException('backup_failed', "Can't create $path");
        }

        if (! $lock->flock(LOCK_EX | LOCK_NB)) {
            throw new UpdateException('update_in_progress', 'Another update is already running.');
        }

        return $lock;
    }

    /**
     * @param  array<string, scalar>  $fields
     *
     * @param-out Response $res
     *
     * @throws UpdateException
     */
    private function call(string $path, array $fields = [], ?string $saveTo = null, ?Response &$res = null): Reply
    {
        if ($errors = $this->configErrors()) {
            throw new UpdateException('notification_script_corrupted', implode(', ', $errors));
        }

        $host = (string) parse_url($this->serverUrl, PHP_URL_HOST);
        $ips = implode('', $this->network->ips($host));
        $today = gmdate('Y-m-d');
        $res = $this->network->post(rtrim($this->serverUrl, '/').$path, $fields + [
            'product_id' => $this->productId,
            'product_key' => $this->productKey,
            'user_local_path' => $this->installDir,
            'script_signature' => $ips === '' ? '' : hash('sha256', $today.$this->productId.$this->productKey.$ips),
        ], $this->timeout, $this->verifySsl, null, $saveTo);

        $expected = hash('sha256', $ips.$this->productKey.$this->productId.$today);

        if ($res->failed() || $ips === '' || ! hash_equals($expected, $res->header('notification_server_signature'))) {
            throw new UpdateException('notification_no_connection', $res->error ?: "Can't reach billing, or its reply isn't signed.");
        }

        // Billing's AFU replies put the data in the body; older ones sent a notification_data header.
        $data = json_decode(($res->header('notification_data') ?: $res->body) ?: '[]', true);

        return new Reply($res->header('notification_case'), $res->header('notification_text'), is_array($data) ? $data : []);
    }
}
