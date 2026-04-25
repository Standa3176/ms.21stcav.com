<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 9 E1 — B2B / Trade Pricing
|--------------------------------------------------------------------------
|
| Two operator-facing settings:
|   - anonymous_display: how prices show to logged-out browsers (D-06)
|   - role_to_group_map: Woo role -> customer_groups.slug mapping (D-07)
|
| Both are read by Phase 9 services at runtime; no code change required to
| flip posture or add a role mapping. Add a new role mapping here + run
| `php artisan config:clear` and the listener picks it up immediately.
*/

return [

    // D-06 — anonymous-user display posture. 'retail' (default) shows retail
    // prices to logged-out browsers; trade customers see their group price
    // after authenticated login. Operator can flip to 'hidden' via env to
    // show "Login to see trade pricing" instead.
    //
    // NOTE — W-06 honesty caveat: the 'hidden' UI gate is NOT yet implemented
    // in Phase 9 (this phase ships the config infrastructure only). The
    // consuming UI (Cart / PDP / quote flow) is built in the next phase that
    // renders prices to anonymous users — Phase 11 (E2 Quote Flow). Until
    // then, 'hidden' is a config no-op.
    'anonymous_display' => env('B2B_ANONYMOUS_DISPLAY', 'retail'),

    // D-07 — Woo user role -> customer_groups.slug mapping. Listener
    // UpdateCustomerGroupOnUserRoleChange reads this on every customer
    // webhook to denormalise users.customer_group_id. Unrecognised roles
    // (or 'customer' default) -> null = retail.
    'role_to_group_map' => [
        'wholesale_customer' => 'trade',
        'wholesale_b2b'      => 'reseller',
        'edu_customer'       => 'education',
        'nhs_customer'       => 'nhs',
    ],

];
