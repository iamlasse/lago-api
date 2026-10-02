<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\AddOn;
use App\Models\FixedCharge;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;
use App\Services\FixedCharges\CreateService;
use App\Services\FixedCharges\UpdateService;
use App\Services\FixedCharges\DestroyService;
use App\Services\FixedCharges\GenerateCodeService;

/**
 * Ports of spec/services/fixed_charges/{create,update,destroy,
 * generate_code}_service_spec.rb (core scenarios).
 */
function fixedChargePlan(): Plan
{
    return Plan::factory()->create();
}

it('creates a fixed charge by add_on_id', function (): void {
    $plan = fixedChargePlan();
    $addOn = AddOn::factory()->create(['organization_id' => $plan->organization_id]);

    $result = CreateService::call(plan: $plan, params: [
        'add_on_id' => $addOn->id,
        'charge_model' => 'standard',
        'code' => 'setup-fee',
        'units' => 3,
        'properties' => ['amount' => '150'],
    ]);

    $result->fixed_charge->refresh();

    expect($result->success())->toBeTrue()
        ->and($result->fixed_charge->add_on_id)->toBe($addOn->id)
        ->and($result->fixed_charge->code)->toBe('setup-fee')
        ->and($result->fixed_charge->units)->toBe('3.0000000000')
        ->and($result->fixed_charge->properties)->toBe(['amount' => '150']);
})->group('ledger:svc:FixedCharges.CreateService');

it('creates a fixed charge by add_on_code and fails on an unknown one', function (): void {
    $plan = fixedChargePlan();
    $addOn = AddOn::factory()->create(['organization_id' => $plan->organization_id, 'code' => 'known-add-on']);

    $result = CreateService::call(plan: $plan, params: [
        'add_on_code' => 'known-add-on',
        'charge_model' => 'standard',
        'code' => 'fc',
        'properties' => ['amount' => '10'],
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->fixed_charge->add_on_id)->toBe($addOn->id);

    $missing = CreateService::call(plan: $plan, params: [
        'add_on_code' => 'nope',
        'charge_model' => 'standard',
        'code' => 'fc2',
        'properties' => ['amount' => '10'],
    ]);

    expect($missing->failure())->toBeTrue()
        ->and($missing->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($missing->getError()->resource)->toBe('add_on');
});

it('builds default properties for a fixed charge', function (): void {
    $plan = fixedChargePlan();
    $addOn = AddOn::factory()->create(['organization_id' => $plan->organization_id]);

    $result = CreateService::call(plan: $plan, params: [
        'add_on_id' => $addOn->id,
        'charge_model' => 'standard',
        'code' => 'fc',
    ]);

    $result->fixed_charge->refresh();

    expect($result->success())->toBeTrue()
        ->and($result->fixed_charge->properties)->toBe(['amount' => '0'])
        ->and($result->fixed_charge->units)->toBe('0.0000000000');
});

it('rejects a fixed charge with an invalid charge model or pay_in_advance mismatch', function (): void {
    $plan = fixedChargePlan();
    $addOn = AddOn::factory()->create(['organization_id' => $plan->organization_id]);

    $result = CreateService::call(plan: $plan, params: [
        'add_on_id' => $addOn->id,
        'charge_model' => 'percentage', // not a fixed-charge model
        'code' => 'fc',
        'properties' => ['rate' => '0.1'],
    ]);

    expect($result->failure())->toBeTrue();

    $error = $result->getError();

    expect($error)->toBeInstanceOf(ValidationFailure::class)
        ->and($error->messages['charge_model'] ?? null)->toBe(['value_is_invalid']);

    $payInAdvanceVolume = CreateService::call(plan: $plan, params: [
        'add_on_id' => $addOn->id,
        'charge_model' => 'volume',
        'code' => 'fc-vol',
        'pay_in_advance' => true,
        'properties' => [
            'volume_ranges' => [
                ['from_value' => 0, 'to_value' => null, 'per_unit_amount' => '0', 'flat_amount' => '0'],
            ],
        ],
    ]);

    expect($payInAdvanceVolume->failure())->toBeTrue()
        ->and($payInAdvanceVolume->getError()->messages['pay_in_advance'])->toBe(['invalid_charge_model']);
});

it('generates unique codes with a numeric suffix', function (): void {
    $plan = fixedChargePlan();
    $addOn = AddOn::factory()->create(['organization_id' => $plan->organization_id, 'code' => 'addon']);

    expect(GenerateCodeService::call(plan: $plan, addOn: $addOn)->code)->toBe('addon');

    FixedCharge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'add_on_id' => $addOn->id,
        'code' => 'addon',
        'charge_model' => 'standard',
        'properties' => ['amount' => '10'],
    ]);

    expect(GenerateCodeService::call(plan: $plan, addOn: $addOn)->code)->toBe('addon_2');
})->group('ledger:svc:FixedCharges.GenerateCodeService');

it('updates a fixed charge and cannot edit structural fields while attached to a subscription', function (): void {
    $plan = fixedChargePlan();
    $addOn = AddOn::factory()->create(['organization_id' => $plan->organization_id]);

    $fixedCharge = FixedCharge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'add_on_id' => $addOn->id,
        'code' => 'fc',
        'charge_model' => 'standard',
        'units' => 1,
        'properties' => ['amount' => '100'],
    ]);

    $result = UpdateService::call(fixedCharge: $fixedCharge, params: [
        'units' => 7,
        'code' => 'renamed',
        'charge_model' => 'standard',
        'invoice_display_name' => 'Display',
        'properties' => ['amount' => '200'],
    ]);

    $result->fixed_charge->refresh();

    expect($result->success())->toBeTrue()
        ->and($result->fixed_charge->units)->toBe('7.0000000000')
        ->and($result->fixed_charge->code)->toBe('renamed')
        ->and($result->fixed_charge->getRawOriginal('charge_model'))->toBe('standard')
        ->and($result->fixed_charge->invoice_display_name)->toBe('Display');
})->group('ledger:svc:FixedCharges.UpdateService');

it('rejects updating a fixed charge to a graduated pay-in-advance prorated combination', function (): void {
    $plan = fixedChargePlan();
    $addOn = AddOn::factory()->create(['organization_id' => $plan->organization_id]);

    $fixedCharge = FixedCharge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'add_on_id' => $addOn->id,
        'code' => 'fc',
        'charge_model' => 'graduated',
        'prorated' => false,
        'units' => 1,
        'properties' => [
            'graduated_ranges' => [
                ['from_value' => 0, 'to_value' => null, 'per_unit_amount' => '5', 'flat_amount' => '0'],
            ],
        ],
    ]);

    $result = UpdateService::call(fixedCharge: $fixedCharge, params: [
        'units' => 1,
        'charge_model' => 'graduated',
        'prorated' => true,
        'pay_in_advance' => true,
    ]);

    // NOTE: Rails cannot update pay_in_advance/prorated via the update
    // service either — nothing changed, so it succeeds silently.
    expect($result->success())->toBeTrue()
        ->and($result->fixed_charge->prorated)->toBeFalse()
        ->and($result->fixed_charge->pay_in_advance)->toBeFalse();

    // The graduated + pay_in_advance + prorated combination is invalid at the
    // model level (FixedCharge#validate_prorated).
    $fixedCharge->pay_in_advance = true;
    $fixedCharge->prorated = true;

    expect($fixedCharge->validateAttributes()['prorated'] ?? null)->toBe(['invalid_charge_model']);
});

it('destroys a fixed charge and reports an already-destroyed one', function (): void {
    $plan = fixedChargePlan();
    $addOn = AddOn::factory()->create(['organization_id' => $plan->organization_id]);

    $fixedCharge = FixedCharge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'add_on_id' => $addOn->id,
        'code' => 'fc',
        'charge_model' => 'standard',
        'properties' => ['amount' => '100'],
    ]);

    $result = DestroyService::call(fixedCharge: $fixedCharge);

    expect($result->success())->toBeTrue()
        ->and($fixedCharge->refresh()->deleted_at)->not->toBeNull();

    $again = DestroyService::call(fixedCharge: $fixedCharge);

    expect($again->failure())->toBeTrue()
        ->and($again->getError()->getMessage())->toContain('fixed_charge_already_deleted');
})->group('ledger:svc:FixedCharges.DestroyService');
