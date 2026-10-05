<?php

declare(strict_types=1);

namespace Faveo\LicenseClient;

use RuntimeException;

/**
 * Pure PHP port of py-machineid (https://github.com/keygen-sh/py-machineid).
 * Resolves a stable, unique identifier for the current host without admin/root
 * privileges.
 *
 *     MachineId::id();
 *     MachineId::hashedId('my-app-id');
 */
final class MachineId
{
    public static function id(bool $useWinRegistry = true): string
    {
        $id = null;

        switch (strtolower(PHP_OS_FAMILY)) {
            case 'darwin':
                $id = self::exec("ioreg -d2 -c IOPlatformExpertDevice | awk -F\\\" '/IOPlatformUUID/{print \$(NF-1)}'");

                break;

            case 'windows':
                if ($useWinRegistry) {
                    $id = self::registry('HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Cryptography', 'MachineGuid');
                }

                if (! $id) {
                    $id = self::exec('powershell.exe -ExecutionPolicy bypass -command "(Get-CimInstance -Class Win32_ComputerSystemProduct).UUID"');
                }

                if (! $id) {
                    $id = self::wmicUuid();
                }

                break;

            case 'linux':
                $id = self::linuxId();

                break;

            case 'bsd':
                $id = self::read('/etc/hostid');

                if (! $id) {
                    $id = self::exec('kenv -q smbios.system.uuid');
                }

                break;
        }

        if (! $id) {
            throw new RuntimeException(sprintf('failed to obtain id on platform %s', PHP_OS_FAMILY));
        }

        return self::sanitize($id);
    }

    public static function hashedId(string $appId = '', bool $useWinRegistry = true): string
    {
        return hash_hmac('sha256', $appId, self::id($useWinRegistry));
    }

    private static function linuxId(): ?string
    {
        $id = self::read('/var/lib/dbus/machine-id');

        if ($id) {
            return $id;
        }

        $id = self::read('/etc/machine-id');

        if ($id) {
            return $id;
        }

        $cgroup = self::read('/proc/self/cgroup');

        if ($cgroup !== null && str_contains($cgroup, 'docker')) {
            $id = self::exec('head -1 /proc/self/cgroup | cut -d/ -f3');

            if ($id) {
                return $id;
            }
        }

        $mountinfo = self::read('/proc/self/mountinfo');

        if ($mountinfo !== null && str_contains($mountinfo, 'docker')) {
            $id = self::exec("grep -oP '(?<=docker/containers/)([a-f0-9]+)(?=/hostname)' /proc/self/mountinfo");

            if ($id) {
                return $id;
            }
        }

        if (str_contains(strtolower(php_uname('r')), 'microsoft')) { // WSL
            $id = self::exec('powershell.exe -ExecutionPolicy bypass -command "(Get-CimInstance -Class Win32_ComputerSystemProduct).UUID"');
        }

        return $id;
    }

    private static function wmicUuid(): ?string
    {
        $out = self::exec('wmic csproduct get uuid');

        if ($out === null) {
            return null;
        }

        $lines = preg_split('/\R/', $out);

        if ($lines === false) {
            return null;
        }

        $lines = array_values(array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== ''));

        return $lines[1] ?? null;
    }

    private static function registry(string $keyName, string $valueName): ?string
    {
        $output = self::exec(sprintf('reg query "%s" /v %s', $keyName, $valueName));

        if ($output === null) {
            return null;
        }

        return preg_match('/REG_SZ\s+(\S+)/', $output, $matches) ? $matches[1] : null;
    }

    private static function exec(string $cmd): ?string
    {
        exec($cmd.(PHP_OS_FAMILY === 'Windows' ? ' 2>NUL' : ' 2>/dev/null'), $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            return null;
        }

        return trim(implode("\n", $output));
    }

    private static function read(string $path): ?string
    {
        if (! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false || $contents === '' ? null : trim($contents);
    }

    private static function sanitize(string $id): string
    {
        return trim(preg_replace('/[\x00-\x1f\x7f-\x9f\s]/', '', $id) ?? '');
    }
}
