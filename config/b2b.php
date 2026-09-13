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
    // ── Quick task 260910-lwv — TRADE STOREFRONT PRICING ──────────────────
    //
    // Consumed by TradeStorefrontPricer + the trade:* commands. Retail pricing
    // does not read any of this.
    //
    // group_id is the B2BKing group whose per-product price meta we publish.
    // Discovered on the live install 2026-09-09 by reading a product back
    // through the Woo REST API: 167507 = "B2B Users" (167509 exists and is
    // empty). The meta key shape is B2BKing's own, verified by writing to it.
    //
    // 2026-09-13 — 167509 is named "Trade" and still has NO users, but it
    // carries a B2BKing dynamic rule "Trade Price = Cost + 8%". That rule reads
    // WOO's own cost field, which was populated once at the 2026-05-23 bulk load
    // and has not been maintained since: 6 of 25 sampled published products
    // (24%) have no Woo cost, every one of them created after that date. If a
    // customer is ever moved into 167509 those products price at GBP 0 + 8% =
    // GBP 0 — including IDS-UVC-P38, which costs us GBP 1,066. Two mechanisms
    // computing the same number is the hazard; this app's prices (group 167507)
    // are competitor-aware, retail-capped and floor-protected, so the dynamic
    // rule should be deleted rather than left armed. Operator action, not code.
    'storefront' => [
        'group_id' => (int) env('B2B_TRADE_GROUP_ID', 167507),
        'meta_key_template' => 'b2bking_regular_product_price_group_%d',

        // Operator, 2026-09-09/10: "trade will always be a max of cost + 15%
        // on special and normal cost", floored at 6%.
        // 260910-rsv — the smallest discount off standard worth publishing.
        // Below this the trade price is suppressed and B2BKing falls back to
        // the standard price, which is more honest than a penny off. Set to 0
        // to restore the previous behaviour.
        'min_discount_pct' => (float) env('B2B_TRADE_MIN_DISCOUNT_PCT', 2.0),

        'min_margin_bps' => (int) env('B2B_TRADE_MIN_MARGIN_BPS', 600),
        'max_margin_bps' => (int) env('B2B_TRADE_MAX_MARGIN_BPS', 1500),

        // 260913-ik1 — run trade:sync daily, after the 08:00 undercut.
        //
        // WHY THIS EXISTS: trade:sync had never been scheduled. It was run once
        // by hand, so (a) every product created since had NO trade price at all
        // — SKU 47132 was created 2026-09-13 11:38 and had no b2bking meta of
        // any kind — and (b) every existing trade price was computed against
        // pre-repricing retail. The 08:00 undercut moved 2,205 retail prices on
        // 2026-09-13 alone, so the gap widened every single day.
        //
        // Defaults TRUE: trade pricing is already live on the storefront and
        // decays without this. The env var is an off switch that needs no
        // deploy, not an opt-in. (The undercut's flag defaults false only
        // because it predates cutover.)
        'sync_schedule_enabled' => (bool) env('B2B_TRADE_SYNC_SCHEDULE_ENABLED', true),
    ],

    'role_to_group_map' => [
        'wholesale_customer' => 'trade',
        'wholesale_b2b' => 'reseller',
        'edu_customer' => 'education',
        'nhs_customer' => 'nhs',
    ],

];
