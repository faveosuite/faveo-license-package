<?php

declare(strict_types=1);
use Faveo\LicenseClient\MachineId;

arch()->preset()->php();

// MachineId runs fixed OS commands (no user input) to read the hardware ID billing binds licences to.
arch()->preset()->security()->ignoring(MachineId::class);

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('Faveo\LicenseClient')
    ->toUseStrictTypes();

arch('the core stays framework-free')
    ->expect('Faveo\\LicenseClient')
    ->not->toUse('Illuminate')
    ->ignoring('Faveo\\LicenseClient\\Laravel');
