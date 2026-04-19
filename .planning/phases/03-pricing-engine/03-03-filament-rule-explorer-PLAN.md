---
phase: 03-pricing-engine
plan: 03
type: execute
wave: 3
depends_on:
  - 03-02
files_modified:
  - app/Domain/Pricing/Filament/Resources/PricingRuleResource.php
  - app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/ListPricingRules.php
  - app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/CreatePricingRule.php
  - app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/EditPricingRule.php
  - app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/RuleExplorer.php
  - app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/SimulatedImpact.php
  - app/Domain/Pricing/Filament/Resources/ProductOverrideResource.php
  - app/Domain/Pricing/Filament/Resources/ProductOverrideResource/Pages/ListProductOverrides.php
  - app/Domain/Pricing/Filament/Resources/ProductOverrideResource/Pages/CreateProductOverride.php
  - app/Domain/Pricing/Filament/Resources/ProductOverrideResource/Pages/EditProductOverride.php
  - app/Domain/Pricing/Services/SimulatedImpactCalculator.php
  - app/Providers/Filament/AdminPanelProvider.php
  - database/seeders/RolePermissionSeeder.php
  - resources/views/filament/pages/rule-explorer.blade.php
  - resources/views/filament/pages/simulated-impact.blade.php
  - tests/Feature/Pricing/PricingRuleResourceAccessTest.php
  - tests/Feature/Pricing/RuleExplorerPageTest.php
  - tests/Feature/Pricing/SimulatedImpactCalculatorTest.php
  - tests/Architecture/PolicyTemplateIntegrityTest.php
autonomous: true
requirements:
  - PRCE-08
  - PRCE-09

must_haves:
  truths:
    - "PricingRuleResource shows a CRUD table with scope, brand/category, margin%, priority, active columns"
    - "Only admin + pricing_manager can CREATE/UPDATE/DELETE a PricingRule; read_only can view"
    - "Rule Explorer page accepts a SKU input and displays effective price + full resolution chain (brand+cat → brand → cat → default)"
    - "Rule Explorer shows 'matched via: override | brand_category | category | brand | default_tier' badge per result"
    - "Simulated Impact view lists SKUs that WOULD change if an edited rule were saved (before saving)"
    - "Simulated Impact shows sku | current_price | proposed_price | delta columns paginated at 50"
    - "ProductOverrideResource exposes list/create/edit with pricing_manager gate; write actions use ->authorize()"
    - "RolePermissionSeeder re-run attaches the new pricing_rule + product_override permissions to pricing_manager role (LIKE patterns match)"
    - "PolicyTemplateIntegrityTest scans include the new Pricing/Policies directory + Pricing models have Gate::policy bindings verified"
  artifacts:
    - path: "app/Domain/Pricing/Filament/Resources/PricingRuleResource.php"
      provides: "Filament 3 CRUD Resource with table + form schemas"
      min_lines: 80
    - path: "app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/RuleExplorer.php"
      provides: "Custom page rendering effective price + chain for any SKU (PRCE-08)"
      contains: "resolve"
    - path: "app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/SimulatedImpact.php"
      provides: "Custom page showing N SKUs affected by a proposed rule change (PRCE-09)"
      contains: "SimulatedImpactCalculator"
    - path: "app/Domain/Pricing/Services/SimulatedImpactCalculator.php"
      provides: "Dry-run resolver+calculator loop over catalogue for a proposed rule"
      min_lines: 40
    - path: "app/Domain/Pricing/Filament/Resources/ProductOverrideResource.php"
      provides: "Filament 3 Resource for ProductOverride CRUD"
      min_lines: 50
    - path: "tests/Feature/Pricing/PricingRuleResourceAccessTest.php"
      provides: "Role-gating tests (admin + pricing_manager can edit; sales + read_only cannot)"
      contains: "pricing_manager"
    - path: "tests/Feature/Pricing/RuleExplorerPageTest.php"
      provides: "Rule Explorer resolves a known SKU and renders the chain"
      contains: "resolution"
  key_links:
    - from: "app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/RuleExplorer.php"
      to: "app/Domain/Pricing/Services/RuleResolver.php"
      via: "constructor/app() resolution"
      pattern: "RuleResolver"
    - from: "app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/SimulatedImpact.php"
      to: "app/Domain/Pricing/Services/SimulatedImpactCalculator.php"
      via: "page action"
      pattern: "SimulatedImpactCalculator"
    - from: "database/seeders/RolePermissionSeeder.php"
      to: "pricing_manager role LIKE pattern"
      via: "`%_pricing_rule` + `%product::override` etc"
      pattern: "product_override"
---

<objective>
Ship Filament 3 Resources for PricingRule + ProductOverride with the two custom pages that make Phase 3 observable to a pricing manager: the Rule Explorer (type-a-SKU → see effective price + full chain) and the Simulated Impact view (edit a rule → see which SKUs would change BEFORE saving). Extend RolePermissionSeeder LIKE patterns so the new Resource permissions auto-attach to pricing_manager when shield:generate runs. Extend PolicyTemplateIntegrityTest to include Phase 3 policies + Gate bindings.

Purpose: Phase 3 success criterion #2 (rule explorer with resolution chain) and #3 (simulated impact before save) are observability requirements — pricing managers MUST be able to trust "why did this price come out this way" and "what will change if I tweak this rule" before they ship. These two custom pages ARE the user-facing value of Phase 3. The Resources themselves are plumbing but need correct RBAC. Splitting from Plan 04 (bulk command) lets Filament development happen in parallel on different files while both depend on Plan 02.

Output:
- `PricingRuleResource` with table + form + RuleExplorer page + SimulatedImpact page + role gates
- `ProductOverrideResource` with table + form + role gates
- `SimulatedImpactCalculator` service (loads all products, iterates resolver+calculator with a hypothetical rule override, emits diff count + per-SKU rows)
- Extended `RolePermissionSeeder` (forward-compat LIKE patterns)
- Extended `PolicyTemplateIntegrityTest` scanning `Domain/Pricing/Policies` + asserting Gate bindings for PricingRule + ProductOverride
- Blade views for the two custom pages
- Role-gating feature test + Rule Explorer render test + SimulatedImpactCalculator unit test
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/phases/03-pricing-engine/03-CONTEXT.md
@.planning/phases/03-pricing-engine/03-01-SUMMARY.md
@.planning/phases/03-pricing-engine/03-02-SUMMARY.md
@.planning/phases/01-foundation/01-02-SUMMARY.md
@.planning/phases/02-supplier-sync/02-04-SUMMARY.md
@CLAUDE.md
@app/Domain/Pricing/Models/PricingRule.php
@app/Domain/Pricing/Models/ProductOverride.php
@app/Domain/Pricing/Policies/PricingRulePolicy.php
@app/Domain/Pricing/Policies/ProductOverridePolicy.php
@app/Domain/Pricing/Services/RuleResolver.php
@app/Domain/Pricing/Services/PriceCalculator.php
@app/Domain/Sync/Filament/Resources/ImportIssueResource.php
@database/seeders/RolePermissionSeeder.php
@tests/Architecture/PolicyTemplateIntegrityTest.php

<interfaces>
<!-- SimulatedImpactCalculator exposes this contract; Plan 04 re-uses it for the bulk command report. -->

```php
namespace App\Domain\Pricing\Services;

final readonly class SimulatedImpactRow
{
    public function __construct(
        public int $productId,
        public ?int $variantId,
        public string $sku,
        public int $currentPennies,
        public int $proposedPennies,
        public int $deltaPennies,
        public string $resolutionSource,  // what WOULD win if the new rule existed
    ) {}
}

final class SimulatedImpactCalculator
{
    public function __construct(
        private readonly RuleResolver $resolver,
        private readonly PriceCalculator $calculator,
    ) {}

    /**
     * Project the effect of a HYPOTHETICAL rule change (not yet persisted) across the catalogue.
     *
     * Strategy: clone the rule model with new attributes (or construct a fresh one for create-flow),
     * run a DB transaction, apply the proposed rule, resolve every product, compute new prices,
     * diff against stored sell_price, then ROLL BACK the transaction so nothing persists.
     *
     * @param  PricingRule  $proposedRule  Hypothetical rule. May be existing (id set) or new.
     * @param  int  $limit                 Max rows returned for the UI (paginated). Full count returned separately.
     * @return array{count:int, rows:array<int, SimulatedImpactRow>}
     */
    public function simulate(PricingRule $proposedRule, int $limit = 50): array;
}
```

Page stub for RuleExplorer (Filament 3 custom Page):

```php
namespace App\Domain\Pricing\Filament\Resources\PricingRuleResource\Pages;

use App\Domain\Pricing\Filament\Resources\PricingRuleResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\Page;

class RuleExplorer extends Page
{
    protected static string $resource = PricingRuleResource::class;
    protected static string $view = 'filament.pages.rule-explorer';
    protected static ?string $title = 'Rule Explorer';
    protected static ?string $navigationLabel = 'Rule Explorer';

    public ?string $sku = null;
    public ?array $resolution = null;  // ['sell_price_pennies', 'chain', 'source', 'matched_rule_id']

    public function lookup(): void
    {
        // calls RuleResolver + PriceCalculator for the entered SKU
    }
}
```

Permission names expected after shield:generate (Phase 1/2 pattern — BOTH separator styles):
- `view_any_pricing_rule`, `view_pricing_rule`, `create_pricing_rule`, `update_pricing_rule`, `delete_pricing_rule`, `restore_pricing_rule`, `force_delete_pricing_rule`
- OR with `::` separator style: `view_any_pricing::rule`, etc.
- Same set for `product_override` / `product::override`
- RolePermissionSeeder LIKE patterns must cover BOTH styles (Phase 2 discovery).
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: PricingRuleResource + ProductOverrideResource + role-gating tests</name>
  <files>
    app/Domain/Pricing/Filament/Resources/PricingRuleResource.php,
    app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/ListPricingRules.php,
    app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/CreatePricingRule.php,
    app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/EditPricingRule.php,
    app/Domain/Pricing/Filament/Resources/ProductOverrideResource.php,
    app/Domain/Pricing/Filament/Resources/ProductOverrideResource/Pages/ListProductOverrides.php,
    app/Domain/Pricing/Filament/Resources/ProductOverrideResource/Pages/CreateProductOverride.php,
    app/Domain/Pricing/Filament/Resources/ProductOverrideResource/Pages/EditProductOverride.php,
    database/seeders/RolePermissionSeeder.php,
    app/Providers/Filament/AdminPanelProvider.php,
    tests/Feature/Pricing/PricingRuleResourceAccessTest.php
  </files>
  <read_first>
    .planning/phases/03-pricing-engine/03-CONTEXT.md,
    app/Domain/Pricing/Models/PricingRule.php,
    app/Domain/Pricing/Models/ProductOverride.php,
    app/Domain/Pricing/Policies/PricingRulePolicy.php,
    app/Domain/Sync/Filament/Resources/ImportIssueResource.php,
    app/Domain/Sync/Filament/Resources/SyncRunResource.php,
    database/seeders/RolePermissionSeeder.php
  </read_first>
  <action>
    Step 1 — generate resource scaffolds:
    ```
    php artisan make:filament-resource PricingRule --model-namespace="App\\Domain\\Pricing\\Models"
    php artisan make:filament-resource ProductOverride --model-namespace="App\\Domain\\Pricing\\Models"
    ```
    The scaffolds land under `app/Filament/Resources/*` — MOVE them (physically relocate the files and update namespaces) to `app/Domain/Pricing/Filament/Resources/` per the Phase 2 domain-local pattern. Update `composer.json` autoload-dev if needed; Laravel's discovery via Filament is namespace-agnostic (PricingRuleResource auto-registers via Filament's `discoverResources` in PanelProvider).

    Step 2 — open `app/Providers/Filament/AdminPanelProvider.php` (or wherever Filament resources are discovered; in Phase 2 the Sync Resources are discovered via `->discoverResources(in: app_path('Domain/Sync/Filament/Resources'))`). ADD discovery for Pricing:
    ```php
    ->discoverResources(in: app_path('Domain/Pricing/Filament/Resources'), for: 'App\\Domain\\Pricing\\Filament\\Resources')
    ```
    Preserve existing Sync + Alerting discoveries.

    Step 3 — edit `app/Domain/Pricing/Filament/Resources/PricingRuleResource.php`:
    - `protected static ?string $model = PricingRule::class;`
    - `protected static ?string $navigationIcon = 'heroicon-o-scale';` (or similar pricing icon)
    - `protected static ?string $navigationGroup = 'Pricing';`
    - `protected static ?int $navigationSort = 10;`
    - `form()` schema (Filament 3 form components):
      * `Select::make('scope')->options([...four enum values...])->required()->reactive()`
      * `Select::make('brand_id')->visible(fn ($get) => in_array($get('scope'), ['brand', 'brand_category']))->numeric()` (plain numeric input; no brands table yet)
      * `Select::make('category_id')->visible(fn ($get) => in_array($get('scope'), ['category', 'brand_category']))->numeric()`
      * `TextInput::make('margin_basis_points')->numeric()->required()->helperText('2200 = 22.00%')`
      * `TextInput::make('priority')->numeric()->default(100)->helperText('Higher wins on ties; default 100')`
      * `Toggle::make('is_default_tier')->reactive()`
      * `TextInput::make('tier_min_pennies')->numeric()->visible(fn ($get) => $get('is_default_tier'))`
      * `TextInput::make('tier_max_pennies')->numeric()->nullable()->visible(fn ($get) => $get('is_default_tier'))`
      * `Toggle::make('active')->default(true)`
    - `table()` schema:
      * Columns: scope (badge), brand_id, category_id, margin_basis_points (format 'X.XX%'), priority, is_default_tier (icon), active (icon), updated_at
      * Filters: active, scope
      * Actions: EditAction + DeleteAction (both ->authorize('update', ...)/->authorize('delete', ...) explicit)
      * Bulk actions: DeleteBulkAction (->authorize('delete', ...))
    - `getPages()` returns:
      ```php
      return [
          'index' => Pages\ListPricingRules::route('/'),
          'create' => Pages\CreatePricingRule::route('/create'),
          'edit' => Pages\EditPricingRule::route('/{record}/edit'),
          'rule-explorer' => Pages\RuleExplorer::route('/rule-explorer'),  // Task 2 creates this
          'simulated-impact' => Pages\SimulatedImpact::route('/{record}/simulated-impact'),  // Task 3
      ];
      ```
    - Authorisation: Resource's `canViewAny()` / `canCreate()` / `canEdit()` delegate to policy automatically via Gate::policy binding from Plan 01. Verify via the access test in Step 6.

    Step 4 — edit `app/Domain/Pricing/Filament/Resources/ProductOverrideResource.php`:
    - `protected static ?string $model = ProductOverride::class;`
    - Navigation group 'Pricing', sort 20
    - `form()`:
      * `Select::make('product_id')->relationship('product', 'sku')->searchable()->required()->unique(ignoreRecord: true)` — enforce D-08 uniqueness at form level on top of DB unique
      * `TextInput::make('margin_basis_points')->numeric()->required()->helperText('2200 = 22.00%; overrides all rules (D-08)')`
      * `Textarea::make('reason')->helperText('Audit trail — why this override exists')`
    - `table()`:
      * Columns: product.sku, margin_basis_points formatted, reason (truncated), created_by_user.name, updated_at
      * Filters: none
      * Actions: Edit + Delete with ->authorize()
      * Bulk: DeleteBulkAction with ->authorize()
    - Pages: standard list/create/edit.

    Step 5 — extend `database/seeders/RolePermissionSeeder.php`. Locate the `$pricingManagerPermissions` query (currently handles `%_pricing_rule`, `%_product`, `%_product_variant`, etc.). The `%_pricing_rule` pattern ALREADY exists from Phase 1 scaffolding. ADD patterns for `product_override` (both separator styles, matching Phase 2 discovery about Shield 3.9.10 multi-word emission):
    ```php
    ->orWhere('name', 'like', '%_product_override')     // underscore style (singular)
    ->orWhere('name', 'like', '%product::override')     // Shield :: style (multi-word)
    ```
    Keep existing Phase 1/2 patterns untouched. Test the seeder:
    ```
    php artisan db:seed --class=RolePermissionSeeder
    ```
    Confirm `pricing_manager` role count goes up after `shield:generate` creates the new permissions (Step 6).

    Step 6 — run `shield:generate` to produce Policies + Permissions for the two new Resources:
    ```
    php artisan shield:generate --resource=PricingRuleResource --resource=ProductOverrideResource
    ```
    **CRITICAL:** shield:generate is DESTRUCTIVE per Phase 1 + Phase 2 experience — it OVERWRITES the hand-written `PricingRulePolicy` + `ProductOverridePolicy` we shipped in Plan 01 with `{{ Placeholder }}` stubs. After running, IMMEDIATELY restore:
    ```
    git checkout HEAD -- app/Domain/Pricing/Policies/PricingRulePolicy.php app/Domain/Pricing/Policies/ProductOverridePolicy.php
    ```
    Then re-run the seeder:
    ```
    php artisan db:seed --class=RolePermissionSeeder
    ```
    Verify no placeholder leak by running PolicyTemplateIntegrityTest (Task 4 will also extend this, but for now the existing test still covers these directories if we update it in Task 4 Step 2).

    Step 7 — author `tests/Feature/Pricing/PricingRuleResourceAccessTest.php`:
    - Test 1 (admin can view index): authenticate admin, GET `/admin/pricing-rules`, assert 200.
    - Test 2 (pricing_manager can view index): same, as pricing_manager.
    - Test 3 (read_only can view index): same, as read_only.
    - Test 4 (sales CANNOT view index): GET returns 403 (or Livewire's equivalent).
    - Test 5 (pricing_manager can create): POST/Livewire CreatePricingRule form with valid data → row persisted.
    - Test 6 (sales CANNOT create): authenticated as sales → 403.
    - Test 7 (read_only CANNOT create): same.
    - Test 8 (admin can delete): DeleteAction succeeds.
    - Test 9 (pricing_manager can update margin): EditAction submits new margin → DB updated.
    - Use Filament's testing helpers: `livewire(ListPricingRules::class)->assertCanSeeTableRecords([...])` or `->assertForbidden()`. Import `Filament\Testing\assertPermission` as needed.
    - For ProductOverrideResource: same 9-test pattern in a separate `it()` group or test file (combine or separate based on length).

    Step 8 — run: `vendor/bin/pest tests/Feature/Pricing/PricingRuleResourceAccessTest.php --stop-on-failure`. All tests MUST pass.

    **DO NOT:**
    - Do NOT skip the Policy restore step after shield:generate. Failing to restore means PricingRulePolicy reverts to a `return true`-on-all-methods stub that grants everyone every permission. Phase 2 caught this bug in Plan 02-04.
    - Do NOT use ->visible() alone on bulk actions — Phase 1 Warning 9 mandates ->authorize() as defence-in-depth.
    - Do NOT FK-constrain brand_id / category_id on the form (no brands/categories tables exist; form uses numeric input).
    - Do NOT add a "create default tier" form option — default tier rows are seeded via DefaultPricingTierSeeder (Plan 01). Admin can edit margin via the normal edit flow.
  </action>
  <verify>
    <automated>php artisan db:seed --class=RolePermissionSeeder --no-interaction && vendor/bin/pest tests/Feature/Pricing/PricingRuleResourceAccessTest.php --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `test -f app/Domain/Pricing/Filament/Resources/PricingRuleResource.php` returns 0
    - `test -f app/Domain/Pricing/Filament/Resources/ProductOverrideResource.php` returns 0
    - `grep -q "protected static \\?string \\\$model = PricingRule::class" app/Domain/Pricing/Filament/Resources/PricingRuleResource.php` (or standard Filament model property)
    - `grep -q "margin_basis_points" app/Domain/Pricing/Filament/Resources/PricingRuleResource.php`
    - `grep -q "is_default_tier" app/Domain/Pricing/Filament/Resources/PricingRuleResource.php`
    - `grep -q "->authorize(" app/Domain/Pricing/Filament/Resources/PricingRuleResource.php` (belt-and-braces on actions)
    - `grep -q "unique" app/Domain/Pricing/Filament/Resources/ProductOverrideResource.php` (product_id unique)
    - `grep -q "product_override" database/seeders/RolePermissionSeeder.php`
    - `grep -q "product::override" database/seeders/RolePermissionSeeder.php`
    - `grep -q "Domain/Pricing/Filament/Resources" app/Providers/Filament/AdminPanelProvider.php` (Pricing resource directory registered with the admin panel)
    - `grep -L "{{ " app/Domain/Pricing/Policies/PricingRulePolicy.php app/Domain/Pricing/Policies/ProductOverridePolicy.php` returns both paths (no placeholder leak)
    - `php artisan db:seed --class=RolePermissionSeeder --no-interaction` exits 0
    - `php artisan tinker --env=testing --execute="echo \\Spatie\\Permission\\Models\\Role::where('name', 'pricing_manager')->first()->permissions()->where('name', 'like', '%pricing_rule')->count() + \\Spatie\\Permission\\Models\\Role::where('name', 'pricing_manager')->first()->permissions()->where('name', 'like', '%pricing::rule')->count();"` prints a value >= 7 (pricing_rule perms)
    - `php artisan tinker --env=testing --execute="echo \\Spatie\\Permission\\Models\\Role::where('name', 'pricing_manager')->first()->permissions()->where('name', 'like', '%product_override')->count() + \\Spatie\\Permission\\Models\\Role::where('name', 'pricing_manager')->first()->permissions()->where('name', 'like', '%product::override')->count();"` prints >= 7 (override perms)
    - `vendor/bin/pest tests/Feature/Pricing/PricingRuleResourceAccessTest.php --stop-on-failure` exits 0
    - `php artisan route:list --name=pricing` (or filament route list) shows routes for pricing-rules and product-overrides
  </acceptance_criteria>
  <done>
    Two Filament Resources with full CRUD + role gating exist. shield:generate output cleaned, policies restored, seeder LIKE patterns attach new permissions to pricing_manager. Access test covers the 4-role matrix.
  </done>
</task>

<task type="auto">
  <name>Task 2: Rule Explorer custom page (PRCE-08) + render test</name>
  <files>
    app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/RuleExplorer.php,
    resources/views/filament/pages/rule-explorer.blade.php,
    tests/Feature/Pricing/RuleExplorerPageTest.php
  </files>
  <read_first>
    .planning/phases/03-pricing-engine/03-CONTEXT.md,
    .planning/phases/03-pricing-engine/03-02-SUMMARY.md,
    app/Domain/Pricing/Services/RuleResolver.php,
    app/Domain/Pricing/Services/PricingResolution.php,
    app/Domain/Pricing/Services/PriceCalculator.php,
    app/Domain/Products/Models/Product.php,
    app/Domain/Pricing/Filament/Resources/PricingRuleResource.php
  </read_first>
  <action>
    Step 1 — author `app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/RuleExplorer.php` extending `Filament\Resources\Pages\Page` with form-filled SKU input, lookup action, and a Livewire-rendered result panel:
    ```php
    namespace App\Domain\Pricing\Filament\Resources\PricingRuleResource\Pages;

    use App\Domain\Pricing\Exceptions\NoPricingRuleMatchedException;
    use App\Domain\Pricing\Filament\Resources\PricingRuleResource;
    use App\Domain\Pricing\Services\PriceCalculator;
    use App\Domain\Pricing\Services\RuleResolver;
    use App\Domain\Products\Models\Product;
    use Filament\Forms\Components\TextInput;
    use Filament\Forms\Concerns\InteractsWithForms;
    use Filament\Forms\Contracts\HasForms;
    use Filament\Forms\Form;
    use Filament\Resources\Pages\Page;

    /**
     * PRCE-08 — Rule Explorer. Pricing manager types a SKU, sees effective price + full resolution chain.
     */
    class RuleExplorer extends Page implements HasForms
    {
        use InteractsWithForms;

        protected static string $resource = PricingRuleResource::class;
        protected static string $view = 'filament.pages.rule-explorer';
        protected static ?string $title = 'Rule Explorer';
        protected static ?string $navigationLabel = 'Rule Explorer';
        protected static ?int $navigationSort = 15;

        public ?array $data = [];
        public ?array $resolution = null;
        public ?string $lastError = null;

        public function mount(): void
        {
            $this->form->fill([]);
        }

        public function form(Form $form): Form
        {
            return $form->schema([
                TextInput::make('sku')->label('SKU')->required()->placeholder('e.g. LOG-C930E'),
            ])->statePath('data');
        }

        public function lookup(): void
        {
            $this->resolution = null;
            $this->lastError = null;

            $sku = trim((string) ($this->data['sku'] ?? ''));
            if ($sku === '') {
                $this->lastError = 'Enter a SKU to look up.';
                return;
            }

            // Resolve product by SKU: check product.sku first, then product_variants.sku.
            $product = Product::where('sku', $sku)->first();
            $variant = null;
            if ($product === null) {
                $variant = \App\Domain\Products\Models\ProductVariant::where('sku', $sku)->first();
                $product = $variant?->product;
            }
            if ($product === null) {
                $this->lastError = "No product found for SKU {$sku}.";
                return;
            }

            try {
                $resolution = app(RuleResolver::class)->resolve($product);
            } catch (NoPricingRuleMatchedException $e) {
                $this->lastError = $e->getMessage();
                return;
            }

            $buyPrice = $variant?->buy_price ?? $product->buy_price;
            $buyPennies = $buyPrice === null ? 0 : (int) round(((float) $buyPrice) * 100);

            if ($buyPennies <= 0) {
                $this->lastError = "Product has zero / null buy_price — no retail price computable (see ImportIssues page).";
                return;
            }

            $sellPennies = app(PriceCalculator::class)->compute($buyPennies, $resolution->marginBasisPoints);

            $this->resolution = [
                'sku' => $sku,
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'buy_pennies' => $buyPennies,
                'sell_pennies' => $sellPennies,
                'margin_basis_points' => $resolution->marginBasisPoints,
                'source' => $resolution->source,
                'matched_rule_id' => $resolution->matchedRuleId,
                'override_id' => $resolution->overrideId,
                'chain' => $resolution->chain,
            ];
        }

        public static function canAccess(): bool
        {
            return auth()->user()?->can('viewAny', \App\Domain\Pricing\Models\PricingRule::class) ?? false;
        }
    }
    ```

    Step 2 — author `resources/views/filament/pages/rule-explorer.blade.php`:
    - Extend Filament's page layout: `<x-filament-panels::page>`
    - Render the form via `{{ $this->form }}`
    - Render an "Look up" button that calls `wire:click="lookup"`
    - If `$lastError` set, show an error alert
    - If `$resolution` set, render a card with:
      - SKU, product/variant IDs
      - "Effective retail price: £X.XX" (format sell_pennies/100 with 2dp)
      - "Margin: XX.XX%" (margin_basis_points/100 with 2dp)
      - Resolution chain as coloured badges: for each step in `resolution.chain`, show a grey badge; the final matching source gets a green badge; override gets a purple badge.
      - Matched rule ID link to edit page when source != 'override'
      - Override ID link to the override edit page when source == 'override'
    - Use Tailwind utility classes matching the ImportIssueResource view style for consistency.

    Step 3 — author `tests/Feature/Pricing/RuleExplorerPageTest.php`:
    - Test 1 (unknown SKU → error): lookup 'NONEXISTENT' → resolution=null, lastError contains 'No product found'.
    - Test 2 (known simple product → resolves): seed default tiers, create Product(sku='TEST-001', buy_price=50.00), lookup 'TEST-001' → resolution array has sell_pennies=8100 (5000 * 13500 * 12000 / 100_000_000), source='default_tier'.
    - Test 3 (brand_category match): product with brand_id+category_id matching a rule → resolution.source === 'brand_category', chain contains ['brand_category'].
    - Test 4 (override precedence): product with override → resolution.source === 'override', chain === ['override'], override_id populated.
    - Test 5 (zero buy_price → error): product with buy_price=0 → lastError contains 'zero' or 'null'.
    - Test 6 (access gate): unauthenticated user → Livewire test receives 403; pricing_manager → 200.
    - Use Filament testing helpers: `livewire(RuleExplorer::class)->fillForm(['sku' => 'TEST-001'])->call('lookup')->assertSet('resolution.sell_pennies', 8100);`

    Step 4 — run: `vendor/bin/pest tests/Feature/Pricing/RuleExplorerPageTest.php --stop-on-failure`. All 6 MUST pass.

    Step 5 — manual probe: start dev server (`php artisan serve`), navigate to `/admin/pricing-rules/rule-explorer`, type a seeded SKU, confirm the resolution panel renders. (This is optional but confirms Blade + Livewire wire correctly.)

    **DO NOT:**
    - Do NOT dispatch ProductPriceChanged from the rule explorer — it is READ-ONLY preview.
    - Do NOT call the PriceCalculator BEFORE the buy_price<=0 guard — the calculator's own guard would throw and the user gets a confusing stack trace. Check the guard FIRST, display a friendly error.
    - Do NOT cache results across lookups — each lookup is a fresh DB read (predictability > micro-perf).
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Pricing/RuleExplorerPageTest.php --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `test -f app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/RuleExplorer.php` returns 0
    - `test -f resources/views/filament/pages/rule-explorer.blade.php` returns 0
    - `grep -q "RuleResolver" app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/RuleExplorer.php`
    - `grep -q "PriceCalculator" app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/RuleExplorer.php`
    - `grep -q "public function lookup" app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/RuleExplorer.php`
    - `grep -q "canAccess" app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/RuleExplorer.php`
    - `grep -q "chain" resources/views/filament/pages/rule-explorer.blade.php`
    - `vendor/bin/pest tests/Feature/Pricing/RuleExplorerPageTest.php --stop-on-failure` exits 0
    - Test count >= 6 passing
  </acceptance_criteria>
  <done>
    Pricing manager can visit /admin/pricing-rules/rule-explorer, type any SKU, and see the effective retail price + full resolution chain with matched source highlighted. Access gated by can('viewAny', PricingRule).
  </done>
</task>

<task type="auto">
  <name>Task 3: SimulatedImpactCalculator + Simulated Impact page (PRCE-09) + test</name>
  <files>
    app/Domain/Pricing/Services/SimulatedImpactCalculator.php,
    app/Domain/Pricing/Services/SimulatedImpactRow.php,
    app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/SimulatedImpact.php,
    resources/views/filament/pages/simulated-impact.blade.php,
    tests/Feature/Pricing/SimulatedImpactCalculatorTest.php
  </files>
  <read_first>
    .planning/phases/03-pricing-engine/03-CONTEXT.md,
    app/Domain/Pricing/Services/RuleResolver.php,
    app/Domain/Pricing/Services/PriceCalculator.php,
    app/Domain/Pricing/Models/PricingRule.php,
    app/Domain/Products/Models/Product.php,
    app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/RuleExplorer.php
  </read_first>
  <action>
    Step 1 — author SimulatedImpactRow DTO under `app/Domain/Pricing/Services/` (final readonly class as per <interfaces>).

    Step 2 — author `app/Domain/Pricing/Services/SimulatedImpactCalculator.php`:
    ```php
    namespace App\Domain\Pricing\Services;

    use App\Domain\Pricing\Models\PricingRule;
    use App\Domain\Products\Models\Product;
    use Illuminate\Support\Facades\DB;

    final class SimulatedImpactCalculator
    {
        public function __construct(
            private readonly RuleResolver $resolver,
            private readonly PriceCalculator $calculator,
        ) {}

        /**
         * Project the effect of a HYPOTHETICAL PricingRule on the catalogue.
         *
         * Wraps the projection in a DB::transaction + rollback — the proposed rule is
         * temporarily persisted, resolver runs against the live state, results
         * collected, rollback. Nothing persists.
         *
         * @return array{count:int, rows:array<int, SimulatedImpactRow>}
         */
        public function simulate(PricingRule $proposedRule, int $limit = 50): array
        {
            $rows = [];
            $count = 0;

            DB::beginTransaction();
            try {
                // Persist the hypothetical rule (replace or insert).
                if ($proposedRule->exists) {
                    $proposedRule->save();
                } else {
                    $proposedRule->save();
                }

                // Iterate products in chunks; compute old vs new per product.
                Product::query()
                    ->whereNotNull('buy_price')
                    ->where('buy_price', '>', 0)
                    ->chunkById(500, function ($chunk) use (&$rows, &$count, $limit) {
                        foreach ($chunk as $product) {
                            try {
                                $resolution = $this->resolver->resolve($product);
                                $buyPennies = (int) round(((float) $product->buy_price) * 100);
                                $proposedPennies = $this->calculator->compute($buyPennies, $resolution->marginBasisPoints);
                                $currentPennies = $product->sell_price === null ? 0 : (int) round(((float) $product->sell_price) * 100);

                                if ($proposedPennies === $currentPennies) {
                                    continue;
                                }

                                $count++;
                                if (count($rows) < $limit) {
                                    $rows[] = new SimulatedImpactRow(
                                        productId: $product->id,
                                        variantId: null,
                                        sku: (string) $product->sku,
                                        currentPennies: $currentPennies,
                                        proposedPennies: $proposedPennies,
                                        deltaPennies: $proposedPennies - $currentPennies,
                                        resolutionSource: $resolution->source,
                                    );
                                }
                            } catch (\Throwable $e) {
                                // Catalogue-incomplete or unusable state — skip this product silently.
                                continue;
                            }
                        }
                    });
            } finally {
                DB::rollBack();
            }

            return ['count' => $count, 'rows' => $rows];
        }
    }
    ```

    Step 3 — author the Simulated Impact page `app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/SimulatedImpact.php`:
    ```php
    namespace App\Domain\Pricing\Filament\Resources\PricingRuleResource\Pages;

    use App\Domain\Pricing\Filament\Resources\PricingRuleResource;
    use App\Domain\Pricing\Models\PricingRule;
    use App\Domain\Pricing\Services\SimulatedImpactCalculator;
    use Filament\Resources\Pages\Page;

    class SimulatedImpact extends Page
    {
        protected static string $resource = PricingRuleResource::class;
        protected static string $view = 'filament.pages.simulated-impact';
        protected static ?string $title = 'Simulated Impact';

        public PricingRule $record;
        public ?array $result = null;

        public function mount(int | string $record): void
        {
            $this->record = PricingRule::findOrFail($record);
            $this->result = null;
        }

        public function simulate(): void
        {
            $this->result = app(SimulatedImpactCalculator::class)->simulate($this->record, limit: 50);
        }

        public static function canAccess(array $parameters = []): bool
        {
            return auth()->user()?->can('update', PricingRule::class) ?? false;
        }
    }
    ```

    Step 4 — author `resources/views/filament/pages/simulated-impact.blade.php`:
    - Show the rule's current state at top (scope, brand/category, margin, priority).
    - "Simulate" button → `wire:click="simulate"`.
    - When `$result` is set:
      - Summary: "N SKUs would change" (where N = result.count).
      - Table: sku | current_price | proposed_price | delta (formatted as £ values from pennies/100; delta shown with + or - sign).
      - Note: "Showing first 50 of {count} — full export via CSV bulk action (Phase 7)".
    - Tailwind classes matching rule-explorer.blade.php.

    Step 5 — author `tests/Feature/Pricing/SimulatedImpactCalculatorTest.php`:
    - Test 1 (dry-run does not persist): create rule, call simulate, after call query pricing_rules and confirm no change (transaction rolled back). Specifically: alter an existing rule's margin, simulate, then refresh — margin unchanged.
    - Test 2 (new rule — count of affected SKUs): seed 5 products with default-tier pricing. Create a fresh PricingRule(scope=brand_category, brand_id=10, category_id=20, margin=4500) but DO NOT save. Set 3 products with matching brand_id+category_id. Call simulate(newRule). Expect result.count = 3 (the 3 whose prices would change); 2 unaffected products stay unmatched.
    - Test 3 (row shape): result.rows[0] instanceof SimulatedImpactRow with deltaPennies = proposedPennies - currentPennies.
    - Test 4 (limit): when 100 products would change, result.count=100 but len(result.rows)=50.
    - Test 5 (skip zero buy_price): products with buy_price=0 are silently skipped (no throw, no row).
    - Test 6 (no change — excluded): products whose proposed price equals current price are NOT in rows.
    - Add Test 7 (page test): livewire(SimulatedImpact::class, ['record' => $rule->id])->call('simulate')->assertSet('result.count', >=0). Assert access gate: sales role → 403.

    Step 6 — run: `vendor/bin/pest tests/Feature/Pricing/SimulatedImpactCalculatorTest.php --stop-on-failure`. All 7 MUST pass.

    **DO NOT:**
    - Do NOT persist the proposed rule without the rollback — the page is a preview; persisting would break the "see before you save" promise.
    - Do NOT load the full catalogue into memory — chunkById(500) is mandatory for the eventual 15k-SKU catalogue.
    - Do NOT skip the try/catch around resolver per product — one broken product (no matching rule, invalid state) shouldn't fail the whole simulation.
    - Do NOT include rows where proposed equals current — the simulation's value is the diff, not the noise.
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Pricing/SimulatedImpactCalculatorTest.php --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `test -f app/Domain/Pricing/Services/SimulatedImpactCalculator.php` returns 0
    - `test -f app/Domain/Pricing/Services/SimulatedImpactRow.php` returns 0
    - `grep -q "final readonly class SimulatedImpactRow" app/Domain/Pricing/Services/SimulatedImpactRow.php`
    - `grep -q "DB::beginTransaction" app/Domain/Pricing/Services/SimulatedImpactCalculator.php`
    - `grep -q "DB::rollBack" app/Domain/Pricing/Services/SimulatedImpactCalculator.php`
    - `grep -q "chunkById" app/Domain/Pricing/Services/SimulatedImpactCalculator.php`
    - `test -f app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/SimulatedImpact.php` returns 0
    - `test -f resources/views/filament/pages/simulated-impact.blade.php` returns 0
    - `grep -q "simulate" app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/SimulatedImpact.php`
    - `grep -q "canAccess" app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/SimulatedImpact.php`
    - `vendor/bin/pest tests/Feature/Pricing/SimulatedImpactCalculatorTest.php --stop-on-failure` exits 0
    - Test count >= 7 passing
  </acceptance_criteria>
  <done>
    Pricing manager can edit any rule, click "Simulated Impact", and see which SKUs would change (count + first 50 rows with sku/current/proposed/delta). Transaction rolls back — no accidental persistence.
  </done>
</task>

<task type="auto">
  <name>Task 4: Extend PolicyTemplateIntegrityTest + Gate binding assertions for Pricing models</name>
  <files>
    tests/Architecture/PolicyTemplateIntegrityTest.php
  </files>
  <read_first>
    tests/Architecture/PolicyTemplateIntegrityTest.php,
    app/Domain/Pricing/Policies/PricingRulePolicy.php,
    app/Domain/Pricing/Policies/ProductOverridePolicy.php,
    app/Domain/Pricing/Models/PricingRule.php,
    app/Domain/Pricing/Models/ProductOverride.php
  </read_first>
  <action>
    Step 1 — open `tests/Architecture/PolicyTemplateIntegrityTest.php` (promoted to Architecture suite in Phase 2). 3 tests currently:
    1. grep for `{{ ` literal across Policy dirs
    2. positive control: >= 7 Policy files exist
    3. Gate::policy bindings for Phase 1 + Phase 2 models resolve to correct classes

    Step 2 — extend Test 1's `$paths` array to include:
    ```php
    app_path('Domain/Pricing/Policies'),
    ```

    Step 3 — update Test 2's positive control count from `>= 7` to `>= 9` (adding PricingRulePolicy + ProductOverridePolicy). Update the comment:
    ```php
    // 9 = RolePolicy + SuggestionPolicy + AlertRecipientPolicy (Phase 1)
    //   + ProductPolicy + ProductVariantPolicy + SyncRunPolicy + ImportIssuePolicy (Phase 2)
    //   + PricingRulePolicy + ProductOverridePolicy (Phase 3)
    ```

    Step 4 — extend Test 3's `$pairs` array with two new entries:
    ```php
    \App\Domain\Pricing\Models\PricingRule::class => \App\Domain\Pricing\Policies\PricingRulePolicy::class,
    \App\Domain\Pricing\Models\ProductOverride::class => \App\Domain\Pricing\Policies\ProductOverridePolicy::class,
    ```

    Step 5 — run the test: `vendor/bin/pest tests/Architecture/PolicyTemplateIntegrityTest.php --stop-on-failure`. All 3 tests MUST pass.

    **DO NOT:**
    - Do NOT remove any existing pair from the $pairs map — the Phase 1/2 assertions are still required.
    - Do NOT lower the positive control threshold — it's a regression guard that catches accidental Policy deletions.
    - Do NOT scan a deeper glob than `*.php` direct-children — the existing test uses `glob($dir.DIRECTORY_SEPARATOR.'*.php')` (non-recursive) which is correct for the flat Policies/ convention.
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Architecture/PolicyTemplateIntegrityTest.php --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `grep -q "Domain/Pricing/Policies" tests/Architecture/PolicyTemplateIntegrityTest.php`
    - `grep -q "PricingRulePolicy::class" tests/Architecture/PolicyTemplateIntegrityTest.php`
    - `grep -q "ProductOverridePolicy::class" tests/Architecture/PolicyTemplateIntegrityTest.php`
    - `grep -q "toBeGreaterThanOrEqual(9" tests/Architecture/PolicyTemplateIntegrityTest.php`
    - `vendor/bin/pest tests/Architecture/PolicyTemplateIntegrityTest.php --stop-on-failure` exits 0
    - 3 tests still present (not 2 or 4)
  </acceptance_criteria>
  <done>
    PolicyTemplateIntegrityTest now scans Pricing/Policies for {{ Placeholder }} regressions, positive-control floor raised to 9, and Gate bindings for both Pricing models assert correct resolution. Phase 3 policies are under permanent architectural guard.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| HTTP request → Filament panel | Auth + Shield permissions gate every resource action; policies wired via Gate::policy |
| Filament form → DB write | FormRequest + Policy ->authorize() + DB UNIQUE constraint (product_id on overrides) layered defence |
| Rule Explorer → RuleResolver | Read-only; no side effects; uses policy viewAny check |
| Simulated Impact → DB | Transactional dry-run; always rolled back; policy 'update' check before access |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-03-03-01 (maps to T1 unauthorised rule mod) | E (Elevation of Privilege) | PricingRuleResource write actions | mitigate | Gate::policy binds PricingRule to PricingRulePolicy (Plan 01). Resource actions call ->authorize('update', ...) explicitly on top of Filament's implicit policy check (Phase 1 Warning 9). Task 1 Test 4-7 assert sales + read_only cannot create/update. Phase 2 PolicyTemplateIntegrityTest extended in Task 4 catches Shield regression. |
| T-03-03-02 (maps to T2 override smuggling) | E (Elevation of Privilege) | ProductOverrideResource create | mitigate | ProductOverridePolicy gates create to admin + pricing_manager. Filament form uses `->unique(ignoreRecord: true)` on product_id; DB UNIQUE constraint is the final guard (D-08). Task 1 Test covers sales 403. |
| T-03-03-03 | T (Tampering) | Simulated Impact persisting accidentally | mitigate | DB::beginTransaction + DB::rollBack in finally. Task 3 Test 1 asserts the rule's stored margin is unchanged post-simulate. |
| T-03-03-04 | I (Information Disclosure) | Rule Explorer exposes pricing chain | accept | Information is internal (resolution source, rule ID, margin) — visible only to users with viewAny PricingRule. No PII. Shield gate enforced via canAccess(). |
| T-03-03-05 (maps to T5 golden-fixture bypass via policy regression) | T (Tampering) | PolicyTemplateIntegrityTest weakened | mitigate | 3 tests (literal grep + positive control + Gate binding). Removing one OR lowering the control floor is visible in code review. Task 4 explicitly raises floor from 7 → 9. |
| T-03-03-06 | D (Denial of Service) | Simulated Impact on 15k-SKU catalogue | accept | chunkById(500) bounds memory. One-off pricing manager action (not routine). If p95 latency becomes an issue, Phase 7 can move this to a queued job; v1 ships as sync-request-bound. |
| T-03-03-07 | S (Spoofing) | Rule Explorer accepts any SKU string | accept | SKU lookup is Eloquent `where('sku', $sku)` — parameter-bound, no SQL injection. `trim()` + `(string)` cast normalises input. |
</threat_model>

<verification>
- `vendor/bin/pest tests/Feature/Pricing/PricingRuleResourceAccessTest.php tests/Feature/Pricing/RuleExplorerPageTest.php tests/Feature/Pricing/SimulatedImpactCalculatorTest.php tests/Architecture/PolicyTemplateIntegrityTest.php --stop-on-failure` — all Plan 03 tests pass
- `php artisan db:seed --class=RolePermissionSeeder --no-interaction` — pricing_manager role receives pricing_rule + product_override permissions
- Manual probe: log in as pricing_manager, visit /admin/pricing-rules, click "Rule Explorer", type any seeded SKU → effective price + chain render
- `grep -L "{{ " app/Domain/Pricing/Policies/*.php` returns both policy paths (no placeholder regression)
</verification>

<success_criteria>
- PricingRuleResource + ProductOverrideResource present with full CRUD + role-gated actions
- Rule Explorer page resolves any SKU and displays full resolution chain + effective price (PRCE-08)
- Simulated Impact view shows count + first 50 affected SKUs with sku/current/proposed/delta (PRCE-09)
- Simulated Impact does NOT persist — DB transaction rolled back
- RolePermissionSeeder LIKE patterns match product_override permissions (both `_` and `::` styles)
- PolicyTemplateIntegrityTest scans Pricing/Policies + asserts Gate bindings for both Pricing models
- All ~30 new tests pass
- `shield:generate` destructive regeneration followed by immediate policy restore + seeder re-run — no placeholder leaks
</success_criteria>

<output>
Create `.planning/phases/03-pricing-engine/03-03-SUMMARY.md` covering:
- Resource locations + navigation group/sort
- Role matrix confirmed (admin + pricing_manager write; read_only view; sales forbidden)
- Rule Explorer page URL + behavioural contract
- Simulated Impact page URL + transactional rollback guarantee
- Seeder LIKE pattern additions + test probe tinker query output
- PolicyTemplateIntegrityTest positive-control floor bump (7 → 9)
- shield:generate destructive-restore pattern followed (policies recovered from HEAD)
- Pointer for Plan 04: PricingRecomputeCommand reuses RuleResolver + PriceCalculator; no Filament dependency
- Pointer for Plan 05: Deptrac layer enforcement + final VERIFICATION gate
</output>
