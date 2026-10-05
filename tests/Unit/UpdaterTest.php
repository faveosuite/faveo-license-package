<?php

declare(strict_types=1);

use Faveo\LicenseClient\Http\Response;
use Faveo\LicenseClient\Tests\Support\FakeBilling;
use Faveo\LicenseClient\Updater\UpdateException;
use Faveo\LicenseClient\Updater\Updater;

beforeEach(function () {
    $this->tmp = tempDir();
    $this->root = $this->tmp.'/app';
    mkdir($this->root.'/vendor/keep/sub', 0777, true);
    mkdir($this->root.'/vendor/old', 0777, true);
    file_put_contents($this->root.'/index.php', 'old index');
    file_put_contents($this->root.'/vendor/keep/sub/state.ini', 'state');
    file_put_contents($this->root.'/vendor/old/gone.php', 'gone');

    $this->billing = new FakeBilling;
    $this->zip = makeZip($this->tmp.'/v2.zip', ['index.php' => 'new index', 'new.php' => 'added', 'vendor/pkg/a.php' => 'pkg']);
    $this->headers = fn (string $zip, string $version = 'v2'): array => [
        'archive_sha256' => (string) hash_file('sha256', $zip),
        'archive_signature' => $this->billing->sign('5|'.$version.'|'.hash_file('sha256', $zip)),
    ];
    $this->serve = function (string $zip, ?array $headers = null): void {
        $this->billing->on('/api/downloadFile', function (array $fields, ?string $saveTo) use ($zip, $headers) {
            copy($zip, (string) $saveTo);

            return $this->billing->updateReply('KEY', 5, headers: $headers ?? ($this->headers)($zip));
        });
    };
    $this->updater = fn (array $over = []): Updater => new Updater(...$over + [
        'serverUrl' => 'https://billing.test',
        'productId' => 5,
        'productKey' => 'KEY',
        'installDir' => $this->root,
        'backupDir' => $this->tmp.'/backups',
        'publicKey' => $this->billing->publicKey,
        'replaceDirs' => ['vendor' => ['keep/sub']],
        'network' => $this->billing,
    ]);
    $this->snapshot = function (): array {
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $files[substr($f->getPathname(), strlen($this->root) + 1)] = file_get_contents($f->getPathname());
        }
        ksort($files);

        return $files;
    };
});

it('installs a signed archive, emptying replace_dirs but keeping preserved paths', function () {
    ($this->serve)($this->zip);
    $backup = ($this->updater)()->apply('v2', 'CODE-1');

    expect(($this->snapshot)())->toBe([
        'index.php' => 'new index',
        'new.php' => 'added',
        'vendor/keep/sub/state.ini' => 'state',
        'vendor/pkg/a.php' => 'pkg',
    ])->and($backup->version())->toBe('v2')
        ->and($this->billing->requests[0]['fields']['license_code'])->toBe('CODE-1');
});

it('rolls back to exactly the previous files', function () {
    $before = ($this->snapshot)();
    ($this->serve)($this->zip);
    $updater = ($this->updater)();
    $updater->apply('v2');
    $updater->rollback();

    expect(($this->snapshot)())->toBe($before);
    expect(fn () => $updater->rollback())->toThrow(UpdateException::class, 'no backup');
});

it('restores the backup automatically when extraction fails', function () {
    $before = ($this->snapshot)();
    ($this->serve)($this->zip);

    try {
        ($this->updater)()->apply('v2', before: fn () => throw new RuntimeException('disk exploded'));
        $this->fail('expected an exception');
    } catch (UpdateException $e) {
        expect($e->rolledBack)->toBeTrue()->and($e->case)->toBe('extract_failed');
    }
    expect(($this->snapshot)())->toBe($before);
});

it('does not roll files back when a post-update step fails', function () {
    ($this->serve)($this->zip);
    $updater = ($this->updater)();

    try {
        $updater->apply('v2', after: fn () => throw new RuntimeException('migration failed'));
        $this->fail('expected an exception');
    } catch (UpdateException $e) {
        expect($e->rolledBack)->toBeFalse()->and($e->case)->toBe('post_update_failed');
    }
    expect(($this->snapshot)()['index.php'])->toBe('new index')
        ->and($updater->backups())->toHaveCount(1);
});

it('refuses archives it cannot trust, changing nothing', function (Closure $setup, string $case) {
    $before = ($this->snapshot)();
    $setup->call($this);

    expect(fn () => ($this->updater)()->apply('v2'))->toThrow(fn (UpdateException $e) => expect($e->case)->toBe($case));
    expect(($this->snapshot)())->toBe($before);
})->with([
    'unsigned' => [function () {
        ($this->serve)($this->zip, []);
    }, 'archive_unsigned'],
    'checksum mismatch' => [function () {
        ($this->serve)($this->zip, ['archive_sha256' => str_repeat('0', 64)] + ($this->headers)($this->zip));
    }, 'archive_checksum_mismatch'],
    'signed for another version' => [function () {
        ($this->serve)($this->zip, ($this->headers)($this->zip, 'v3'));
    }, 'archive_signature_invalid'],
    'signed by someone else' => [function () {
        $other = new FakeBilling;
        ($this->serve)($this->zip, ['archive_signature' => $other->sign('5|v2|'.hash_file('sha256', $this->zip))] + ($this->headers)($this->zip));
    }, 'archive_signature_invalid'],
    'zip slip' => [function () {
        ($this->serve)(makeZip($this->tmp.'/evil.zip', ['../escape.php' => 'x']));
    }, 'archive_unsafe'],
    'unsigned reply' => [function () {
        $this->billing->on('/api/downloadFile', fn () => new Response(['notification_server_signature' => 'forged'], 'zip'));
    }, 'notification_no_connection'],
    'refused by billing' => [function () {
        // Billing refuses with a signed reply and a JSON body, which curl still writes to the file.
        $this->billing->on('/api/downloadFile', function (array $fields, ?string $saveTo) {
            file_put_contents((string) $saveTo, '[]');

            return $this->billing->updateReply('KEY', 5, 'notification_version_not_found', '[]');
        });
    }, 'notification_version_not_found'],
]);

it('refuses symlinks and oversized archives', function () {
    $zip = new ZipArchive;
    $zip->open($this->tmp.'/link.zip', ZipArchive::CREATE);
    $zip->addFromString('link', '/etc/passwd');
    $zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, (0120777 << 16));
    $zip->close();
    ($this->serve)($this->tmp.'/link.zip');
    expect(fn () => ($this->updater)()->apply('v2'))->toThrow(UpdateException::class, 'Symlink');

    ($this->serve)(makeZip($this->tmp.'/big.zip', ['big.bin' => str_repeat('x', 2 * 1024 * 1024)]));
    expect(fn () => ($this->updater)(['maxArchiveMb' => 1])->apply('v2'))->toThrow(UpdateException::class, 'larger');
});

it('allows unsigned archives only when explicitly configured', function () {
    ($this->serve)($this->zip, []);
    ($this->updater)(['requireSignedArchives' => false, 'publicKey' => null])->apply('v2');

    expect(file_get_contents($this->root.'/index.php'))->toBe('new index');
});

it('refuses to run two updates at once', function () {
    mkdir($this->tmp.'/backups');
    $lock = fopen($this->tmp.'/backups/.lock', 'c');
    flock($lock, LOCK_EX);
    ($this->serve)($this->zip);

    expect(fn () => ($this->updater)()->apply('v2'))->toThrow(fn (UpdateException $e) => expect($e->case)->toBe('update_in_progress'));
});

it('keeps only the newest keep_backups backups', function () {
    ($this->serve)($this->zip);
    $updater = ($this->updater)(['keepBackups' => 2]);
    foreach (range(1, 3) as $_) {
        $updater->apply('v2');
    }

    expect($updater->backups())->toHaveCount(2);
});

it('never lets replace_dirs wipe the package running the update', function () {
    $packageRoot = dirname(__DIR__, 2);
    // installed as <install>/vendor/<name>: vendor/ is a replace dir, so <name> must be kept
    $vendorStyle = new Updater('https://billing.test', 5, 'KEY', dirname($packageRoot, 2), $this->tmp.'/b', replaceDirs: [basename(dirname($packageRoot)) => ['x']], requireSignedArchives: false);
    expect((new ReflectionMethod($vendorStyle, 'protectedReplaceDirs'))->invoke($vendorStyle))
        ->toBe([basename(dirname($packageRoot)) => ['x', basename($packageRoot)]]);
});

it('lists the newer public versions for the release channel, oldest first', function (string $channel, array $expected) {
    // Billing's AFU replies carry the data in the body, not in a notification_data header.
    $this->billing->on('/api/getAllVersions', fn () => new Response(
        ['notification_case' => 'notification_operation_ok'] + array_diff_key($this->billing->updateReply('KEY', 5)->headers, ['notification_data' => 1]),
        (string) json_encode(['product_versions' => [
            ['version' => 'v9.4.0', 'release_type' => 'beta', 'is_private' => 0],
            ['version' => 'v9.3.2', 'release_type' => 'pre_release', 'is_private' => 0],
            ['version' => 'v9.3.1', 'release_type' => 'official', 'is_private' => 1],
            ['version' => 'v9.3.10', 'release_type' => 'official', 'is_private' => 0],
            ['version' => 'v_9_3_1', 'release_type' => null, 'is_private' => 0],
            ['version' => 'v9.3.0', 'release_type' => 'official', 'is_private' => 0],
            ['version' => 'v9.2.9', 'release_type' => 'official', 'is_private' => 0],
        ]]),
    ));

    expect(array_column(($this->updater)()->available('v9.3.0', $channel), 'version'))->toBe($expected);
})->with([
    'official' => ['official', ['v_9_3_1', 'v9.3.10']],
    'pre_release' => ['pre_release', ['v_9_3_1', 'v9.3.2', 'v9.3.10']],
    'beta' => ['beta', ['v_9_3_1', 'v9.3.2', 'v9.3.10', 'v9.4.0']],
]);

it('tells billing\'s yes from a refusal', function () {
    $this->billing->on('/api/getVersions', fn (array $f) => $this->billing->updateReply('KEY', 5, $f['version_number'] === 'v2' ? 'notification_operation_ok' : 'notification_version_not_found', data: ['version' => 'v2']));

    expect(($this->updater)()->version('v2')->ok())->toBeTrue()
        ->and(($this->updater)()->version('v9')->ok())->toBeFalse();
});

it('lists the add-ons billing says the licence can install', function () {
    $row = ['product_id' => 12, 'product_name' => 'Calendar Pro', 'product_key' => 'CAL', 'version' => 'v2.0.1', 'license_code' => 'CODE-1', 'path' => 'Calendar'];
    $this->billing->on('/api/licensedPlugins', fn (array $f) => $f['license_code'] === 'CODE-1'
        ? $this->billing->updateReply('KEY', 5, 'notification_operation_ok', data: ['plugins' => [$row]])
        : $this->billing->updateReply('KEY', 5, 'notification_license_not_found'));

    expect(($this->updater)()->plugins('CODE-1'))->toBe([$row])
        ->and(fn () => ($this->updater)()->plugins('OTHER'))
        ->toThrow(fn (UpdateException $e) => expect($e->case)->toBe('notification_license_not_found'));
});

it('refuses an add-on list that billing did not sign', function () {
    $this->billing->on('/api/licensedPlugins', fn () => new Response([
        'notification_case' => 'notification_operation_ok',
        'notification_data' => (string) json_encode(['plugins' => [['product_id' => 12, 'path' => '../../evil']]]),
    ]));

    expect(fn () => ($this->updater)()->plugins('CODE-1'))
        ->toThrow(fn (UpdateException $e) => expect($e->case)->toBe('notification_no_connection'));
});
