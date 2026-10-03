<?php

declare(strict_types=1);

use App\Models\Charge;
use App\Models\BillableMetric;
use App\Serializers\V1\ChargeSerializer;

/**
 * Port of spec/serializers/v1/charge_serializer_spec.rb — the exact JSON
 * shape of a serialized charge (see PlanSerializerTest for the nested
 * plan.charges view and the filters collection).
 */
it('serializes a standard charge with literal snake_case keys', function (): void {
    $organization = App\Models\Organization::factory()->create();
    $metric = BillableMetric::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'api_calls',
    ]);
    $charge = Charge::factory()->create([
        'plan_id' => App\Models\Plan::factory()->create(['organization_id' => $organization->id])->id,
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'code' => 'api_calls',
        'invoice_display_name' => 'API usage',
        'charge_model' => 'standard',
        'properties' => ['amount' => '10'],
        'pay_in_advance' => false,
        'invoiceable' => true,
        'prorated' => false,
        'min_amount_cents' => 0,
    ]);

    $payload = (new ChargeSerializer($charge))->serialize();

    expect($payload['lago_id'])->toBe($charge->id)
        ->and($payload['lago_billable_metric_id'])->toBe($metric->id)
        ->and($payload['lago_parent_id'])->toBeNull()
        ->and($payload['code'])->toBe('api_calls')
        ->and($payload['invoice_display_name'])->toBe('API usage')
        ->and($payload['billable_metric_code'])->toBe('api_calls')
        ->and($payload['charge_model'])->toBe('standard')
        ->and($payload['invoiceable'])->toBeTrue()
        ->and($payload['regroup_paid_fees'])->toBeNull()
        ->and($payload['pay_in_advance'])->toBeFalse()
        ->and($payload['prorated'])->toBeFalse()
        ->and($payload['min_amount_cents'])->toBe(0)
        ->and($payload['accepts_target_wallet'])->toBeFalse()
        ->and($payload['properties'])->toBe(['amount' => '10'])
        ->and($payload['applied_pricing_unit'])->toBeNull()
        ->and($payload['filters'])->toBe([])
        ->and($payload['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        // taxes only with the include (Rails: belongs_to :taxes, if: :include?).
        ->and(array_key_exists('taxes', $payload))->toBeFalse();
})->group('ledger:ser:V1.ChargeSerializer');

it('mirrors grouped_by into pricing_group_keys in the properties payload', function (): void {
    $organization = App\Models\Organization::factory()->create();
    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);
    $charge = Charge::factory()->create([
        'plan_id' => App\Models\Plan::factory()->create(['organization_id' => $organization->id])->id,
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'properties' => ['amount' => '10', 'grouped_by' => ['region']],
    ]);

    $payload = (new ChargeSerializer($charge))->serialize();

    expect($payload['properties']['grouped_by'])->toBe(['region'])
        ->and($payload['properties']['pricing_group_keys'])->toBe(['region']);
})->group('ledger:ser:V1.ChargeSerializer');

it('includes taxes when requested', function (): void {
    $organization = App\Models\Organization::factory()->create();
    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);
    $charge = Charge::factory()->create([
        'plan_id' => App\Models\Plan::factory()->create(['organization_id' => $organization->id])->id,
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'properties' => ['amount' => '10'],
    ]);
    $tax = App\Models\Tax::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'VAT',
        'rate' => 20.0,
    ]);
    Illuminate\Support\Facades\DB::table('charges_taxes')->insert([
        'id' => (string) Illuminate\Support\Str::uuid(),
        'charge_id' => $charge->id,
        'tax_id' => $tax->id,
        'organization_id' => $organization->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = (new ChargeSerializer($charge, ['includes' => ['taxes']]))->serialize();

    expect($payload['taxes'])->toHaveCount(1)
        ->and($payload['taxes'][0]['lago_id'])->toBe($tax->id)
        ->and($payload['taxes'][0]['lago_id'])->toBe($tax->id)
        ->and($payload['taxes'][0]['name'])->toBe('VAT')
        ->and($payload['taxes'][0]['rate'])->toBe(20.0);
})->group('ledger:ser:V1.ChargeSerializer');
