<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 04 Task 3 — P12-F regression guard
|--------------------------------------------------------------------------
|
| Plan 12-04 extends EditAutoCreateReview ADDITIVELY with a new method
| `seoPatchesInfolist()`. The existing Phase 6 form schema MUST stay
| byte-identical — admin's edit form behaviour cannot regress.
|
| Five fixtures:
|   1. AutoCreateReviewResource::form() schema contains every Phase 6 field
|      (sku, name, slug, short_description, long_description, meta_description,
|      auto_create_status, completeness_score) by name.
|   2. The new EditAutoCreateReview::seoPatchesInfolist method exists via
|      reflection.
|   3. EditAutoCreateReview implements HasInfolists for the sidebar mount.
|   4. EditAutoCreateReview source contains the literal RepeatableEntry +
|      Section + Action::make('approve_selected_patches') (P12-F static
|      grep — fence against accidental removal).
|   5. EditAutoCreateReview source does NOT override the form/infolist
|      methods (the parent EditRecord's defaults stay intact).
*/

use App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateReviewResource;
use App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateReviewResource\Pages\EditAutoCreateReview;

it('AutoCreateReviewResource::form() source contains every Phase 6 field (P12-F regression)', function () {
    // Source-level grep — Filament 3.3's Form::make() requires a real
    // HasForms livewire component to instantiate; spinning up a Livewire
    // double here is more brittle than the P12-F invariant we're guarding,
    // which is "no Phase 6 form field was removed". Grep on the literal
    // TextInput::make('field')/Textarea::make('field')/Select::make('field')
    // call sites achieves the same regression-detection contract.
    $path = base_path('app/Domain/ProductAutoCreate/Filament/Resources/AutoCreateReviewResource.php');
    expect(is_file($path))->toBeTrue();
    $src = (string) file_get_contents($path);

    $expected = [
        'sku',
        'name',
        'slug',
        'short_description',
        'long_description',
        'meta_description',
        'auto_create_status',
        'completeness_score',
    ];

    foreach ($expected as $field) {
        $patterns = [
            sprintf("TextInput::make('%s')", $field),
            sprintf("Textarea::make('%s')", $field),
            sprintf("Select::make('%s')", $field),
        ];
        $present = false;
        foreach ($patterns as $pattern) {
            if (str_contains($src, $pattern)) {
                $present = true;
                break;
            }
        }
        expect($present)->toBeTrue(
            "Phase 6 form field '{$field}' missing from AutoCreateReviewResource source — P12-F regression"
        );
    }
});

it('EditAutoCreateReview has a seoPatchesInfolist method (new in Plan 12-04)', function () {
    expect(method_exists(EditAutoCreateReview::class, 'seoPatchesInfolist'))->toBeTrue(
        'P12-F: Plan 12-04 ADDS a seoPatchesInfolist method (NOT overrides infolist).'
    );
});

it('EditAutoCreateReview implements HasInfolists for the sidebar mount', function () {
    $interfaces = class_implements(EditAutoCreateReview::class);
    expect(in_array(\Filament\Infolists\Contracts\HasInfolists::class, (array) $interfaces, true))->toBeTrue();
});

it('EditAutoCreateReview source contains Section + RepeatableEntry + approve action (P12-F fence)', function () {
    $path = base_path('app/Domain/ProductAutoCreate/Filament/Resources/AutoCreateReviewResource/Pages/EditAutoCreateReview.php');
    expect(is_file($path))->toBeTrue();
    $src = (string) file_get_contents($path);
    expect($src)->toContain('seoPatchesInfolist');
    expect($src)->toContain('Section::make');
    expect($src)->toContain('RepeatableEntry');
    expect($src)->toContain('latestSeoSuggestion');
    expect($src)->toContain("Action::make('approve_selected_patches'");
});

it('EditAutoCreateReview does NOT override form() or infolist() (P12-F additive-only)', function () {
    $reflection = new ReflectionClass(EditAutoCreateReview::class);
    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
    $declaredHere = array_filter($methods, fn (ReflectionMethod $m) => $m->getDeclaringClass()->getName() === EditAutoCreateReview::class);
    $methodNames = array_map(fn (ReflectionMethod $m) => $m->getName(), $declaredHere);

    expect(in_array('form', $methodNames, true))->toBeFalse(
        'P12-F: EditAutoCreateReview must NOT override form() — Phase 6 form schema stays byte-identical via AutoCreateReviewResource::form().'
    );
    expect(in_array('infolist', $methodNames, true))->toBeFalse(
        'P12-F: EditAutoCreateReview must NOT override infolist() — the new SEO sidebar uses a DIFFERENTLY-NAMED method seoPatchesInfolist.'
    );
});
