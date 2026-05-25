<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 9 Plan 02 — Brand recolor + nav restructure (4 groups).
|--------------------------------------------------------------------------
|
| Filament Shield's RoleResource reads its sidebar group + label + icon
| from these translation strings (see vendor/bezhansalleh/filament-shield/
| src/Resources/RoleResource.php::getNavigationGroup/getNavigationLabel).
|
| Overriding `nav.group` and `nav.role.label` here is the supported way to
| place Roles under the new "Admin" group + rename the package-name leak
| ("Filament Shield") to ops-friendly "Roles & Permissions" — without
| editing vendor files or extending the Resource.
|
| Only the keys we override are listed; every other translation key falls
| through to the package's bundled `vendor/bezhansalleh/filament-shield/
| resources/lang/en/filament-shield.php`.
*/
return [
    'nav.group' => 'Settings',
    'nav.role.label' => 'Roles & Permissions',
];
