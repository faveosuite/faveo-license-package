<?php

declare(strict_types=1);

use Faveo\LicenseClient\Tests\TestCase;
use Faveo\LicenseClient\Updater\Backup;

uses(TestCase::class)->in(__DIR__);

/** A fresh temp directory, removed after the test. */
function tempDir(): string
{
    $dir = sys_get_temp_dir().'/lc_test_'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    register_shutdown_function(fn () => Backup::delete($dir));

    return $dir;
}

/** @param  array<string, string>  $files */
function makeZip(string $path, array $files): string
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();

    return $path;
}
