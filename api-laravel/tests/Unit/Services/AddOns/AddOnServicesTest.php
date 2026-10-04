<?php

declare(strict_types=1);

use App\Models\Tax;
use App\Models\AddOn;
use App\Models\Organization;
use App\Support\CurrentContext;
use Illuminate\Support\Facades\DB;
use App\Services\AddOns\CreateService;
use App\Services\AddOns\UpdateService;
use App\Services\AddOns\DestroyService;
use App\Services\AddOns\ApplyTaxesService;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;

beforeEach(function (): void {
    CurrentContext::reset();
});

function createAddOnParams(array $overrides = []): array
{
    return [
        'name' => 'add_on',
        'code' => 'add_on_code',
        'amount_cents' => 200,
        'amount_currency' => 'EUR',
        'description' => 'description',
        ...$overrides,
    ];
}

// -- CreateService -----------------------------------------------------------

it('creates an add-on', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(args: createAddOnParams([
        'organization_id' => $organization->id,
    ]));

    expect($result->success())->toBeTrue()
        ->and(AddOn::count())->toBe(1)
        ->and($result->add_on)->toBeInstanceOf(AddOn::class)
        ->and($result->add_on->name)->toBe('add_on')
        ->and($result->add_on->code)->toBe('add_on_code')
        ->and($result->add_on->amount_cents)->toBe(200)
        ->and($result->add_on->amount_currency)->toBe('EUR');
})->group('ledger:svc:AddOns.CreateService');

it('does not apply taxes when no tax_codes key is carried', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(args: createAddOnParams([
        'organization_id' => $organization->id,
    ]));

    expect($result->success())->toBeTrue()
        ->and(DB::table('add_ons_taxes')->count())->toBe(0);
});

it('applies the carried tax codes on create', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $tax = Tax::factory()->create(['organization_id' => $organization->id]);

    $result = CreateService::call(args: createAddOnParams([
        'organization_id' => $organization->id,
        'tax_codes' => [$tax->code],
    ]));

    expect($result->success())->toBeTrue()
        ->and($result->add_on->taxes()->pluck('code')->toArray())->toBe([$tax->code]);
});

it('rejects an unknown tax code', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(args: createAddOnParams([
        'organization_id' => $organization->id,
        'tax_codes' => ['nope'],
    ]));

    expect($result->success())->toBeFalse()
        ->and(AddOn::count())->toBe(0)
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->resource)->toBe('tax');
});

it('rejects a duplicate code in the organization', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    AddOn::factory()->create(['organization_id' => $organization->id, 'code' => 'add_on_code']);

    $result = CreateService::call(args: createAddOnParams([
        'organization_id' => $organization->id,
    ]));

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class);
});

it('rejects a non-positive amount', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(args: createAddOnParams([
        'organization_id' => $organization->id,
        'amount_cents' => 0,
    ]));

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class);
});

// -- UpdateService -------------------------------------------------------------

it('updates only the carried keys', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

    $result = UpdateService::call(addOn: $addOn, params: ['name' => 'renamed']);

    expect($result->success())->toBeTrue()
        ->and($result->add_on->fresh()->name)->toBe('renamed')
        // The untouched keys keep their values.
        ->and($result->add_on->fresh()->description)->toBe('test description');
})->group('ledger:svc:AddOns.UpdateService');

it('returns not_found when the updated add-on does not exist', function (): void {
    $result = UpdateService::call(addOn: null, params: ['name' => 'renamed']);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class);
});

it('rejects a duplicate code on update', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);
    AddOn::factory()->create(['organization_id' => $organization->id, 'code' => 'taken']);

    $result = UpdateService::call(addOn: $addOn, params: ['code' => 'taken']);

    expect($result->success())->toBeFalse();
});

// -- DestroyService --------------------------------------------------------------

it('discards the add-on and its fixed charges', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);
    $fixedCharge = App\Models\FixedCharge::factory()->create([
        'organization_id' => $organization->id,
        'add_on_id' => $addOn->id,
    ]);

    $result = DestroyService::call(addOn: $addOn);

    expect($result->success())->toBeTrue()
        ->and(AddOn::count())->toBe(0)
        ->and($addOn->fresh()->trashed())->toBeTrue()
        // The fixed charges go with it.
        ->and($fixedCharge->fresh()->deleted_at)->not->toBeNull();
})->group('ledger:svc:AddOns.DestroyService');

it('returns not_found when the destroyed add-on does not exist', function (): void {
    $result = DestroyService::call(addOn: null);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class);
});

// -- ApplyTaxesService -------------------------------------------------------------

it('syncs the add-on taxes to the given codes', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $taxA = Tax::factory()->create(['organization_id' => $organization->id, 'code' => 'tax_a']);
    $taxB = Tax::factory()->create(['organization_id' => $organization->id, 'code' => 'tax_b']);

    $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

    $result = ApplyTaxesService::call(addOn: $addOn, taxCodes: [$taxA->code]);

    expect($result->success())->toBeTrue()
        ->and($result->applied_taxes)->toHaveCount(1)
        ->and($addOn->taxes()->pluck('code')->toArray())->toBe([$taxA->code]);

    // Swapping the list removes the tax that fell out and adds the new one.
    $result = ApplyTaxesService::call(addOn: $addOn, taxCodes: [$taxB->code]);

    expect($result->success())->toBeTrue()
        ->and($addOn->taxes()->pluck('code')->toArray())->toBe([$taxB->code]);

    // Re-applying is a no-op (find_or_create).
    ApplyTaxesService::call(addOn: $addOn, taxCodes: [$taxB->code]);

    expect(DB::table('add_ons_taxes')->count())->toBe(1);
})->group('ledger:svc:AddOns.ApplyTaxesService');

it('returns not_found when a tax code does not exist', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

    $result = ApplyTaxesService::call(addOn: $addOn, taxCodes: ['missing']);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->resource)->toBe('tax');
});
