<?php

declare(strict_types=1);

use Faveo\LicenseClient\Tests\Support\FakeBilling;
use Faveo\LicenseClient\Updater\Bundle;
use Faveo\LicenseClient\Updater\UpdateException;

beforeEach(function () {
    $this->tmp = tempDir();
    $this->billing = new FakeBilling;
    $this->update = makeZip($this->tmp.'/update.zip', ['index.php' => 'new']);
    $this->open = fn (string $path): Bundle => Bundle::open($path, $this->billing->publicKey, $this->tmp);
});

it('opens a signed update file and exposes its manifest', function () {
    $bundle = ($this->open)($this->billing->makeBundle($this->tmp.'/b.zip', $this->update));

    expect($bundle->version)->toBe('v2')
        ->and($bundle->productId)->toBe(7)
        ->and($bundle->licenseCode)->toBe('CODE-1')
        ->and(hash_file('sha256', $bundle->archivePath))->toBe(hash_file('sha256', $this->update));

    $path = $bundle->archivePath;
    unset($bundle);
    expect(file_exists($path))->toBeFalse(); // temp copy cleaned up
});

it('refuses update files that are not genuine or intact', function (Closure $make, string $case) {
    expect(fn () => ($this->open)($make->call($this)))->toThrow(fn (UpdateException $e) => expect($e->case)->toBe($case));
})->with([
    'not a zip' => [fn () => tap($this->tmp.'/x.zip', fn ($p) => file_put_contents($p, 'nope')), 'bundle_invalid'],
    'plain update zip' => [fn () => $this->update, 'bundle_invalid'],
    'signed by someone else' => [fn () => (new FakeBilling)->makeBundle($this->tmp.'/b.zip', $this->update), 'bundle_signature_invalid'],
    'unsigned' => [fn () => $this->billing->makeBundle($this->tmp.'/b.zip', $this->update, signWith: ''), 'bundle_signature_invalid'],
    'archive swapped' => [function () {
        $path = $this->billing->makeBundle($this->tmp.'/b.zip', $this->update);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('update.zip', 'malicious');
        $zip->close();

        return $path;
    }, 'archive_checksum_mismatch'],
    'bad manifest' => [fn () => $this->billing->makeBundle($this->tmp.'/b.zip', $this->update, ['sha256' => 'short']), 'bundle_invalid'],
]);
