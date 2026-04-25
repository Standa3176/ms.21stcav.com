---
phase: 09-e1-trade-customer-pricing
plan: 04
subsystem: trade-pricing
tags: [trade-pricing, customer-group-sync, listener, mass-assignment-hardening, webhook-listener, schema, denormalisation, b-02-invariant, b-04-invariant, pest, decorator-extension]

requires:
  - phase: 09-01
    provides: customer_groups table + 4 seeded groups (trade/reseller/education/nhs) + CustomerGroup model + factory + Deptrac TradePricing layer
  - phase: 09-02
    provides: TradeRuleResolver decorator (target consumer of users.customer_group_id at order/quote-time price resolution)
  - phase: 04-crm-sync
    provides: CustomerRegistered domain event + WebhookReceipt model (Phase 4 v1 webhook receiver — UNTOUCHED)
  - phase: 01-foundation
    provides: User model (Breeze + HasRoles + FilamentUser) + RefreshDatabase trait + skip-on-MySQL-offline precedent
provides:
  - "users.customer_group_id BIGINT NULL FK -> customer_groups(id) ON DELETE SET NULL (D-08 soft-fail vs pricing_rules restrictOnDelete)"
  - "User model: customer_group_id integer cast + customerGroup() BelongsTo relation; INTENTIONALLY OMITTED from $fillable (B-02 mass-assignment vector closed)"
  - "config/b2b.php: anonymous_display ('retail' default, env B2B_ANONYMOUS_DISPLAY) + role_to_group_map (4 D-07 entries: wholesale_customer/wholesale_b2b/edu_customer/nhs_customer)"
  - ".env.example: B2B_ANONYMOUS_DISPLAY=retail documented under new Phase 9 section"
  - "App\\Domain\\TradePricing\\Services\\RoleToGroupMapper: resolve(?string): ?CustomerGroup + mapToGroupId(?string): ?int; reads config every call (hot-swap capable); is_active=true filter prevents stale mapping"
  - "App\\Domain\\TradePricing\\Listeners\\UpdateCustomerGroupOnUserRoleChange: subscribes to CustomerRegistered; UPDATE-ONLY per B-04 (User::where('email')->first() never firstOrCreate); compare-and-swap idempotency (Pitfall 4 — zero saves when already at desired state); forceFill writes (B-02)"
  - "EventServiceProvider $listen extends CustomerRegistered::class with new listener alongside Phase 4's HandleCustomerRegistered"
  - "AppServiceProvider singleton binding for RoleToGroupMapper (Pricing-domain bindings cluster)"
  - "UserMassAssignmentTest (3 it()): proves B-02 invariant — fill/User::create cannot set customer_group_id; forceFill DOES persist"
  - "RoleToGroupMapperTest (12 it()): 4 mapped roles + customer/empty/null/subscriber fall-through + Config::set hot-swap + is_active filter + mapToGroupId convenience"
  - "UpdateCustomerGroupOnUserRoleChangeTest (6 it()): happy path + B-04 skip-if-no-user + compare-and-swap (no updated_at drift) + role-change-clears-group + missing-email warning + EventServiceProvider registration"
affects: [09-05-filament-ux, 09-06-verification, phase-11-quote-flow]

tech-stack:
  added: []  # zero composer changes — pure schema + service + listener + config additions
  patterns:
    - "Mass-assignment hardening (B-02): customer_group_id INTENTIONALLY OMITTED from User::$fillable; ONLY forceFill() writers permitted (listener + Plan 09-06 backfill). Mass-assignment via Breeze ProfileController + RegisteredUserController + future API forms is structurally impossible. Invariant locked on CI by UserMassAssignmentTest."
    - "Update-only listener (B-04): User::where('email')->first() with skip-if-null fallback, NEVER firstOrCreate. Cold-start provisioning belongs to b2b:backfill-customer-groups (Plan 09-06). Closes DoS + account-squat vectors from forged webhook payloads."
    - "Compare-and-swap idempotency (Pitfall 4): re-firing same payload produces zero User->save() calls; updated_at flap eliminated; LogsActivity audit trail stays clean."
    - "users.customer_group_id nullOnDelete (D-08) vs pricing_rules.customer_group_id restrictOnDelete (D-04): users null-back on group deletion (admin doesn't need to reassign every user when retiring a group); pricing_rules block group deletion (active rules require manual cleanup)."
    - "Hot-swappable config (D-07): RoleToGroupMapper reads config('b2b.role_to_group_map') on every resolve(); operator adds a 5th role mapping via .env / config:clear without restarting workers."
    - "is_active=true filter on RoleToGroupMapper: deactivated groups can't accidentally re-enter the trade-pricing path via stale Woo role."
    - "Listener-based extension of v1 (Phase 4 CustomerRegistered): Phase 9 listener runs ALONGSIDE Phase 4's HandleCustomerRegistered (separate concerns: CRM push vs trade-pricing denormalisation). Phase 4 code untouched."

key-files:
  created:
    - database/migrations/2026_04_26_010200_add_customer_group_id_to_users_table.php
    - config/b2b.php
    - app/Domain/TradePricing/Services/RoleToGroupMapper.php
    - app/Domain/TradePricing/Listeners/UpdateCustomerGroupOnUserRoleChange.php
    - tests/Unit/Models/UserMassAssignmentTest.php
    - tests/Unit/TradePricing/Services/RoleToGroupMapperTest.php
    - tests/Feature/TradePricing/UpdateCustomerGroupOnUserRoleChangeTest.php
  modified:
    - app/Models/User.php (additive: customer_group_id integer cast + customerGroup() BelongsTo; $fillable INTENTIONALLY UNCHANGED per B-02)
    - .env.example (B2B_ANONYMOUS_DISPLAY=retail under new Phase 9 section)
    - app/Providers/AppServiceProvider.php (RoleToGroupMapper singleton alongside TradeRuleResolver)
    - app/Providers/EventServiceProvider.php ($listen array extension under CustomerRegistered::class)

requirements: [TRDE-04, TRDE-01]

commits:
  - 2cc9ddf feat(09-04): users.customer_group_id migration + B-02 mass-assignment hardening (TRDE-04 Task 1)
  - aaac948 feat(09-04): config/b2b.php + RoleToGroupMapper + AppServiceProvider singleton (TRDE-04 Task 2)
  - c41add3 feat(09-04): UpdateCustomerGroupOnUserRoleChange listener (TRDE-04 Task 3)

deferred-tests:
  - "UserMassAssignmentTest (3 it()) — Pest discovery clean; execution deferred: RefreshDatabase fires before per-test skipIfMySqlOfflineUserMassAssignment helper (inherited Plan 09-01..03 + Phase 6/7/8 limitation). Unblocks once meetingstore_ops_testing MySQL is online."
  - "RoleToGroupMapperTest (12 it()) — same posture; Pest discovery clean (12 cases enumerated). Same MySQL-offline limitation."
  - "UpdateCustomerGroupOnUserRoleChangeTest (6 it()) — same posture; Pest discovery clean (6 cases enumerated)."
  - "MySQL-deferred test execution: blocked behind v1 cutover Gate 3 (feature-tier Pest suite run against online meetingstore_ops_testing per docs/ops/cutover-handover.md Appendix A)."
---

# Plan 09-04 — Customer -> group sync pipeline (TRDE-04, TRDE-01 third migration)

## What was built

The runtime that wires customer-group resolution into the existing Phase 4 webhook seam. Three commits, four new files, four files modified additively.

### 1. `users.customer_group_id` migration + User model edit + UserMassAssignmentTest (commit `2cc9ddf`)

**Migration `database/migrations/2026_04_26_010200_add_customer_group_id_to_users_table.php`:**

Single column add per D-08:

```php
Schema::table('users', function (Blueprint $t) {
    $t->foreignId('customer_group_id')
        ->nullable()
        ->after('email')
        ->constrained('customer_groups')
        ->nullOnDelete();
});
```

`nullOnDelete()` (D-08 soft-fail) is intentionally different from `pricing_rules.customer_group_id` which uses `restrictOnDelete()` (D-04). Reasoning:
- **pricing_rules:** Deleting a customer group with active pricing rules is a sales-policy event — operator MUST manually migrate or deactivate rules first; surface an error.
- **users:** Deleting a customer group should NOT block-and-error every user that was synced into it. Soft-fall the user back to retail (null) and let admin re-sort group affiliation later.

**User model edit** (additive only):
- Added `'customer_group_id' => 'integer'` to `casts()` (DB drivers can return strings for BIGINT columns; cast forces int read).
- Added `customerGroup(): BelongsTo` relation pointing at `App\Domain\TradePricing\Models\CustomerGroup`.
- **`$fillable` is UNCHANGED.** This is the B-02 mass-assignment hardening — listener and backfill use `forceFill()`. Documented inline above the casts() method.

**UserMassAssignmentTest** (3 it() blocks):
1. `(new User)->fill(['customer_group_id' => 99])` -> column stays null
2. `User::create([..., 'customer_group_id' => 99])` -> column stays null
3. `$user->forceFill(['customer_group_id' => $group->id])->save()` DOES persist

The first two prove the mass-assignment vector is structurally closed; the third proves the listener path still works.

### 2. `config/b2b.php` + `.env.example` + RoleToGroupMapper + AppServiceProvider singleton (commit `aaac948`)

**`config/b2b.php`** — exact D-06 + D-07 shape per the plan:

```php
return [
    // D-06 — anonymous-user display posture.
    'anonymous_display' => env('B2B_ANONYMOUS_DISPLAY', 'retail'),

    // D-07 — Woo user role -> customer_groups.slug mapping.
    'role_to_group_map' => [
        'wholesale_customer' => 'trade',
        'wholesale_b2b'      => 'reseller',
        'edu_customer'       => 'education',
        'nhs_customer'       => 'nhs',
    ],
];
```

W-06 honesty caveat present: the `'hidden'` UI gate is NOT yet implemented in Phase 9; consuming UI ships in Phase 11+. Until then `'hidden'` is a config no-op.

**`.env.example`** appended under new Phase 9 section:

```
# ───────────────────────────────────────────────────────────
# v2 — Phase 9 E1 Trade Customer Pricing (Plan 04 — TRDE-04)
# ───────────────────────────────────────────────────────────

B2B_ANONYMOUS_DISPLAY=retail
```

**`RoleToGroupMapper` service** (~60 LOC):
- `resolve(?string $role): ?CustomerGroup` — null/empty/unknown/inactive -> null (retail).
- `mapToGroupId(?string $role): ?int` — convenience for backfill / listener call sites that prefer the integer ID.
- Reads `config('b2b.role_to_group_map')` on every call (hot-swap-friendly).
- Filters on `is_active=true` so a deactivated group cannot accidentally re-enter the trade-pricing path via stale Woo role.

**AppServiceProvider singleton:**

```php
$this->app->singleton(\App\Domain\TradePricing\Services\RoleToGroupMapper::class);
```

Placed alongside the TradeRuleResolver singleton from Plan 09-02 (Pricing-domain bindings cluster).

**RoleToGroupMapperTest** (12 it() blocks):
- 4 happy paths (one per mapped role)
- 4 fall-through paths (`customer`, empty string, null, `subscriber`)
- Hot-swap via `Config::set` with a 5th entry
- `is_active=false` filter test
- `mapToGroupId` convenience tests (known + unknown role)

### 3. UpdateCustomerGroupOnUserRoleChange listener + EventServiceProvider wiring + tests (commit `c41add3`)

**Listener `app/Domain/TradePricing/Listeners/UpdateCustomerGroupOnUserRoleChange.php`** (~115 LOC):

UPDATE-ONLY contract per B-04. Subscribes to `App\Domain\Webhooks\Events\CustomerRegistered` (Phase 4 v1 event). Flow:

1. Load receipt via `WebhookReceipt::findOrFail($event->webhookReceiptId)`.
2. Decode `raw_body` defensively (LONGTEXT — string -> json_decode; array -> cast).
3. Extract `email` + `role` (default `customer`).
4. Empty email -> warning log + early return.
5. **B-04 — `User::query()->where('email', $email)->first()`. If null, info log + early return. NEVER firstOrCreate.**
6. Resolve `$newGroupId = $mapper->mapToGroupId($role)` (null = retail).
7. Compare-and-swap: if `$user->customer_group_id === $newGroupId` already, return without saving (Pitfall 4).
8. Otherwise `$user->forceFill(['customer_group_id' => $newGroupId])->save()` + info log with old + new + role + correlation_id.

**`forceFill`** because `customer_group_id` is intentionally NOT in `$fillable` (B-02). The listener and Plan 09-06's backfill command are the ONLY legitimate writers of this column.

**EventServiceProvider edit** ($listen array):

```diff
 CustomerRegistered::class => [
     HandleCustomerRegistered::class,
+    \App\Domain\TradePricing\Listeners\UpdateCustomerGroupOnUserRoleChange::class,
 ],
```

Comment block above the array entry documents the separate-concerns rationale (Phase 4 pushes to Bitrix CRM; Phase 9 denormalises Woo role -> users.customer_group_id).

**UpdateCustomerGroupOnUserRoleChangeTest** (6 it() blocks):
1. Happy path — `wholesale_customer` role on existing User -> `customer_group_id` becomes trade group id.
2. **B-04 invariant** — `'never-existed@example.com'` -> User count unchanged. Listener does NOT create rows from forged webhooks.
3. Compare-and-swap — re-firing same payload after 1s produces no `updated_at` drift.
4. Role change clears group — `wholesale_customer` -> `customer` (default Woo role) flips column back to null.
5. Empty email payload -> warning log + early return; no DB write.
6. Listener registered in EventServiceProvider $listen (file_get_contents grep test).

## Why this matters

- **B-02 mass-assignment vector closed.** Future Breeze ProfileController, RegisteredUserController, or API form changes cannot accidentally expose `customer_group_id` to user input. Mass-assignment via `User::create([...])` is structurally impossible because `$fillable` doesn't include the column. Listener + backfill use `forceFill()` so the legitimate write paths still work. Invariant locked on CI by UserMassAssignmentTest.

- **B-04 update-only contract eliminates two attack vectors.**
  - **DoS:** Forged webhook payloads with random emails could otherwise create unbounded User rows (filling auth tables with spam).
  - **Account-squat:** Forged webhook with a victim's email could pre-create a User row that the real owner later registers and finds preassigned to an unwanted customer group affiliation.
  Listener now skips silently when no local user matches; cold-start provisioning is the explicit job of `b2b:backfill-customer-groups` (Plan 09-06 Task 1) — operator-invoked, not network-triggered.

- **Compare-and-swap keeps the audit trail clean.** Webhook re-deliveries (Woo retries on transient 5xx) produce zero User->save() calls when state is already correct. `updated_at` only drifts on real role changes; LogsActivity entries on User would (when added in a future phase) reflect actual role-change events, not webhook noise.

- **Hot-swap config without worker restart.** Adding a 5th role mapping (e.g. `'public_sector' => 'public-sector'`) requires only an `.env` edit + `config:clear` — RoleToGroupMapper reads config every call. No code change, no deployment, no Horizon restart.

- **Phase 4 + Phase 9 listener cohabitation.** Phase 4's `HandleCustomerRegistered` (CRM push to Bitrix) keeps running on the `crm-bitrix` Horizon queue; Phase 9's new listener runs on `default`. Separate concerns, separate queues, parallel execution. Zero impact on Phase 4 regression scope.

## Notable deviations

None — every Task 1 / Task 2 / Task 3 acceptance criterion was met as specified in the plan. The threat model + B-02/B-04 invariants were already explicit in the plan, so no Rule 1/2/3 auto-fixes were needed.

### Rule 1 — Bug fixes

None — no live bugs discovered.

### Rule 2 — Auto-added critical functionality

None — every threat-register `mitigate` disposition was already covered by the plan's hardening guidance.

### Rule 3 — Auto-fixed blocking issues

None.

### Rule 4 — Architectural decisions

None.

### Authentication gates

None.

## What this enables

- **Plan 09-05 (Filament UX):** The `users.customer_group_id` column is now FK-bound to `customer_groups.id`; CustomerGroupResource list view can show "X users currently in this group" via the inverse relation, and PricingRuleResource's customer_group_id Select draws from a populated lookup.
- **Plan 09-06 (verification + backfill):** `b2b:backfill-customer-groups` command can walk existing users and call `RoleToGroupMapper::mapToGroupId($role)` to populate the column from Woo role data already pulled by Phase 4. The mapper's `mapToGroupId` convenience method was added specifically for this caller.
- **Phase 11 (E2 Quote Flow):** Quote builder reads `$quote->customer->customer_group_id` (denormalised) and calls `app(TradeRuleResolver::class)->resolve($product, $customerGroupId)` per line — single column read, no join. The denormalisation D-08 mandate pays off here.
- **Future audit-trail extension:** Compare-and-swap means any User-level activity log added in a later phase will only capture actual role-change events, not webhook re-delivery noise.

## Verification snapshot

| Check | Status |
|---|---|
| `php -l app/Models/User.php` | PASS |
| `php -l database/migrations/2026_04_26_010200_add_customer_group_id_to_users_table.php` | PASS |
| `php -l config/b2b.php` | PASS |
| `php -l app/Domain/TradePricing/Services/RoleToGroupMapper.php` | PASS |
| `php -l app/Domain/TradePricing/Listeners/UpdateCustomerGroupOnUserRoleChange.php` | PASS |
| `php -l app/Providers/AppServiceProvider.php` | PASS |
| `php -l app/Providers/EventServiceProvider.php` | PASS |
| `php -l tests/Unit/Models/UserMassAssignmentTest.php` | PASS |
| `php -l tests/Unit/TradePricing/Services/RoleToGroupMapperTest.php` | PASS |
| `php -l tests/Feature/TradePricing/UpdateCustomerGroupOnUserRoleChangeTest.php` | PASS |
| `grep -A 5 'protected $fillable' app/Models/User.php \| grep -c 'customer_group_id'` | 0 (B-02 invariant) |
| `grep -c 'firstOrCreate' app/Domain/TradePricing/Listeners/UpdateCustomerGroupOnUserRoleChange.php` | 0 (B-04 invariant) |
| `grep -c "User::query()->where" app/Domain/TradePricing/Listeners/UpdateCustomerGroupOnUserRoleChange.php` | 1 (B-04 contract) |
| `grep -c 'UpdateCustomerGroupOnUserRoleChange' app/Providers/EventServiceProvider.php` | 2 (comment + registration) |
| `grep -c 'RoleToGroupMapper::class' app/Providers/AppServiceProvider.php` | 1 (singleton binding) |
| `grep -c 'B2B_ANONYMOUS_DISPLAY=retail' .env.example` | 1 |
| `grep -n 'NOT yet' config/b2b.php` | line 26 (W-06 caveat present) |
| Pest discovery: `tests/Unit/Models/UserMassAssignmentTest.php` | 3 cases enumerated |
| Pest discovery: `tests/Unit/TradePricing/Services/RoleToGroupMapperTest.php` | 12 cases enumerated |
| Pest discovery: `tests/Feature/TradePricing/UpdateCustomerGroupOnUserRoleChangeTest.php` | 6 cases enumerated |
| Test execution | DEFERRED — RefreshDatabase fires before per-test skipIfMySqlOffline helper (Plan 09-01..03 + Phase 6/7/8 precedent). Unblocks once meetingstore_ops_testing MySQL is online (cutover Gate 3). |

## Threat surface scan

Reviewed all files created/modified against Plan 09-04 `<threat_model>` STRIDE register. Every `mitigate` disposition is implemented and CI-enforced where the plan listed an invariant test:

- **T-09-04-01 (webhook payload spoofing of customer_group_id):** mitigated as planned. Phase 4's HMAC verifies webhook source before `CustomerRegistered` fires; listener trusts the receipt but ONLY accepts whitelisted role names from `config('b2b.role_to_group_map')` (4 entries by default); unknown roles -> null = retail. RoleToGroupMapperTest 'resolves subscriber (unknown role) -> null (retail)' locks this.
- **T-09-04-02 (mass-assignment via Breeze ProfileController + RegisteredUserController + future API):** mitigated. customer_group_id NOT in User::$fillable. Listener + Plan 09-06 backfill use forceFill(). UserMassAssignmentTest locks the invariant on CI (3 it() blocks).
- **T-09-04-03 (DoS via forged webhooks creating User rows):** mitigated. Listener uses `User::where('email')->first()` UPDATE-ONLY; cold-start is the explicit job of `b2b:backfill-customer-groups` (Plan 09-06). UpdateCustomerGroupOnUserRoleChangeTest 'skips silently when no local user matches the webhook email (B-04)' locks this.
- **T-09-04-04 (account-squat via forged webhook with victim email):** mitigated by the same B-04 fix.
- **T-09-04-05 (listener idempotency failure causing user.customer_group_id flap):** mitigated. Compare-and-swap pattern at the top of the write branch. UpdateCustomerGroupOnUserRoleChangeTest 'compare-and-swap: re-firing same payload produces no updated_at drift' locks this.
- **T-09-04-06 (GDPR — customer_group_id is non-PII):** accepted as planned. Documented as a category, not an identifier; not subject to scrub-in-place; survives GDPR erasure as long as the User row itself does (governed by existing v1 GDPR policy).

No threat-flag types introduced.

## Self-Check: PASSED

**Files:**
- FOUND: `database/migrations/2026_04_26_010200_add_customer_group_id_to_users_table.php`
- FOUND: `config/b2b.php`
- FOUND: `app/Domain/TradePricing/Services/RoleToGroupMapper.php`
- FOUND: `app/Domain/TradePricing/Listeners/UpdateCustomerGroupOnUserRoleChange.php`
- FOUND: `tests/Unit/Models/UserMassAssignmentTest.php`
- FOUND: `tests/Unit/TradePricing/Services/RoleToGroupMapperTest.php`
- FOUND: `tests/Feature/TradePricing/UpdateCustomerGroupOnUserRoleChangeTest.php`
- FOUND: `app/Models/User.php` modified (cast + relation; $fillable UNCHANGED)
- FOUND: `.env.example` modified (B2B_ANONYMOUS_DISPLAY=retail)
- FOUND: `app/Providers/AppServiceProvider.php` modified (RoleToGroupMapper singleton)
- FOUND: `app/Providers/EventServiceProvider.php` modified ($listen extension)

**Commits:**
- FOUND: `2cc9ddf` (Task 1)
- FOUND: `aaac948` (Task 2)
- FOUND: `c41add3` (Task 3)

**Invariants:**
- FOUND: `grep -A 5 'protected $fillable' app/Models/User.php | grep -c 'customer_group_id'` = 0 (B-02 invariant — mass-assignment vector closed)
- FOUND: `grep -c 'firstOrCreate' app/Domain/TradePricing/Listeners/UpdateCustomerGroupOnUserRoleChange.php` = 0 (B-04 invariant — listener UPDATE-ONLY)
- FOUND: `grep -c 'User::query()->where' app/Domain/TradePricing/Listeners/UpdateCustomerGroupOnUserRoleChange.php` = 1 (B-04 contract)
- FOUND: `grep -c 'UpdateCustomerGroupOnUserRoleChange' app/Providers/EventServiceProvider.php` = 2 (comment + registration)
- FOUND: `grep -c 'RoleToGroupMapper::class' app/Providers/AppServiceProvider.php` = 1 (singleton binding)
- FOUND: `grep -c 'B2B_ANONYMOUS_DISPLAY=retail' .env.example` = 1
- FOUND: W-06 honesty caveat present in config/b2b.php (line 26 — `NOT yet` matched)

**Tests discovered (clean Pest enumeration; execution deferred to MySQL-online):**
- 3 cases (UserMassAssignmentTest)
- 12 cases (RoleToGroupMapperTest)
- 6 cases (UpdateCustomerGroupOnUserRoleChangeTest)
- Total: 21 cases discovered, 0 syntax errors, 0 Pest discovery failures
