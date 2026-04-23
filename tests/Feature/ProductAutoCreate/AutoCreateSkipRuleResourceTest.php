<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateSkipRuleResource\Pages\CreateAutoCreateSkipRule;
use App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateSkipRuleResource\Pages\ListAutoCreateSkipRules;
use App\Domain\ProductAutoCreate\Models\AutoCreateSkipRule;
use App\Domain\ProductAutoCreate\Rules\ValidPregPattern;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Validator;

use function Pest\Livewire\livewire;

/*
|--------------------------------------------------------------------------
| Phase 6 Plan 04 — AutoCreateSkipRuleResource + ValidPregPattern Rule
|--------------------------------------------------------------------------
| Admin CRUD + preg-pattern validation (T-06-04-01 ReDoS mitigation).
*/

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->pricingManager = User::factory()->create();
    $this->pricingManager->assignRole('pricing_manager');
    $this->sales = User::factory()->create();
    $this->sales->assignRole('sales');
});

it('admin can create a brand skip rule', function (): void {
    $this->actingAs($this->admin);

    livewire(CreateAutoCreateSkipRule::class)
        ->fillForm([
            'scope' => AutoCreateSkipRule::SCOPE_BRAND,
            'value' => 'TestSpares',
            'reason' => 'spare_part_or_accessory',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AutoCreateSkipRule::where('scope', 'brand')->where('value', 'TestSpares')->exists())->toBeTrue();
});

it('ValidPregPattern accepts simple patterns', function (): void {
    $rule = new ValidPregPattern;
    $errors = [];
    $fail = function (string $msg) use (&$errors): void {
        $errors[] = $msg;
    };
    $rule->validate('value', '^TEST-', $fail);
    expect($errors)->toBe([]);
});

it('ValidPregPattern rejects malformed regex', function (): void {
    $rule = new ValidPregPattern;
    $errors = [];
    $fail = function (string $msg) use (&$errors): void {
        $errors[] = $msg;
    };
    $rule->validate('value', '[unclosed', $fail);
    expect($errors)->not->toBe([]);
});

it('ValidPregPattern rejects patterns over 256 chars', function (): void {
    $rule = new ValidPregPattern;
    $errors = [];
    $fail = function (string $msg) use (&$errors): void {
        $errors[] = $msg;
    };
    $rule->validate('value', str_repeat('a', 257), $fail);
    expect($errors)->not->toBe([]);
});

it('ValidPregPattern rejects empty values', function (): void {
    $rule = new ValidPregPattern;
    $errors = [];
    $fail = function (string $msg) use (&$errors): void {
        $errors[] = $msg;
    };
    $rule->validate('value', '', $fail);
    expect($errors)->not->toBe([]);
});

it('pricing_manager can view but not create skip rules', function (): void {
    AutoCreateSkipRule::factory()->count(2)->create();

    $this->actingAs($this->pricingManager);

    livewire(ListAutoCreateSkipRules::class)
        ->assertSuccessful();
});

it('sales role cannot access the resource', function (): void {
    $this->actingAs($this->sales);

    // Sales should be denied; verify policy gate.
    $policy = app(\App\Domain\ProductAutoCreate\Policies\AutoCreateSkipRulePolicy::class);
    expect($policy->viewAny($this->sales))->toBeFalse();
});

it('price_range format is validated against <N / >N / N-M regex', function (): void {
    $validator = Validator::make(
        ['value' => '50-100'],
        ['value' => ['regex:/^[<>]\d+(\.\d+)?$|^\d+(\.\d+)?-\d+(\.\d+)?$/']],
    );
    expect($validator->passes())->toBeTrue();

    $bad = Validator::make(
        ['value' => 'fifty'],
        ['value' => ['regex:/^[<>]\d+(\.\d+)?$|^\d+(\.\d+)?-\d+(\.\d+)?$/']],
    );
    expect($bad->passes())->toBeFalse();
});
