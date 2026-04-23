<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Intervention Image configuration (Phase 6 Plan 02)
|--------------------------------------------------------------------------
|
| Published manually from vendor/intervention/image-laravel/config/image.php
| — intervention/image-laravel 1.5.0 exposes `$this->publishes([...])` with
| NO tag, so `php artisan vendor:publish --tag=config` is a no-op. We copy
| the file into config/ so ops can introspect + tune the driver / strip /
| auto-orientation settings without editing vendor/.
|
| Driver choice:
|   - GD (ships with PHP — default on this project; no ext-imagick dependency)
|   - Imagick — only available if ext-imagick is installed (not in the current
|     Windows dev image; Linux VPS can switch via INTERVENTION_IMAGE_DRIVER)
|
| ProductImageProcessor strips EXIF explicitly via ->toWebp(..., strip: true)
| so the global 'strip' setting is left at its package default (false) — we
| want EXIF preserved globally by default (e.g. for ops-uploaded images),
| only stripped on the Woo product-image pipeline.
*/

return [

    // Swap to Imagick on the Linux VPS if ext-imagick is installed.
    'driver' => env(
        'INTERVENTION_IMAGE_DRIVER_CLASS',
        \Intervention\Image\Drivers\Gd\Driver::class
    ),

    'options' => [
        'autoOrientation' => true,
        'decodeAnimation' => true,
        'blendingColor' => 'ffffff',
        // Keep false — our ProductImageProcessor strips EXIF explicitly on
        // the Woo product-image pipeline via ->toWebp(strip: true).
        'strip' => false,
    ],
];
