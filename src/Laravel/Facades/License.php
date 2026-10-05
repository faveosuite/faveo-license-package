<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel\Facades;

use Faveo\LicenseClient\Laravel\LicenseManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Faveo\LicenseClient\Status status(string $addon = '')
 * @method static \Faveo\LicenseClient\Status for(string $addon)
 * @method static \Faveo\LicenseClient\Status verify(bool $force = false, string $addon = '')
 * @method static \Faveo\LicenseClient\Status install(string $email, string $licenseCode, string $addon = '', ?string $rootUrl = null)
 * @method static string activationCode(string $licenseCode, string $addon = '', ?string $rootUrl = null)
 * @method static \Faveo\LicenseClient\Status import(string $contents, string $addon = '', ?string $rootUrl = null)
 * @method static void uninstall(string $addon = '')
 * @method static bool ok(string $addon = '')
 * @method static bool allows(string $feature, string $addon = '')
 * @method static int|null limit(string $name, string $addon = '')
 * @method static bool withinLimit(string $name, int $count, string $addon = '')
 * @method static \DateTimeImmutable|null expiresAt(string $name, string $addon = '')
 * @method static string message(\Faveo\LicenseClient\Status $status)
 * @method static \Faveo\LicenseClient\License license(string $addon = '')
 * @method static \Faveo\LicenseClient\Updater\Updater updater(string $addon = '', array<string, mixed> $with = [])
 *
 * @see LicenseManager
 */
final class License extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LicenseManager::class;
    }
}
