<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Serializers\V1\FeeSerializer;

/**
 * Port of spec/serializers/v1/fee_serializer_spec.rb — the exact JSON shape
 * of a serialized fee for the charge and subscription fee types.
 */
function serializedChargeFee(array $feeOverrides = [], array $options = []): array
{
    $organization = App\Models\Organization::factory()->create();
    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $plan = App\Models\Plan::factory()->create(['organization_id' => $organization->id, 'invoice_display_name' => 'Start plan']);
    $metric = App\Models\BillableMetric::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'api',
        'name' => 'API',
        'description' => 'API calls',
    ]);
    $charge = App\Models\Charge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'properties' => ['amount' => '1'],
        'pay_in_advance' => false,
    ]);
    $invoice = App\Models\Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'currency' => 'EUR',
    ]);
    $subscription = App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
        'external_id' => 'sub-fee-1',
    ]);

    $fee = Fee::factory()->chargeFee()->create(array_merge([
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
        'organization_id' => $organization->id,
        'billing_entity_id' => $customer->billing_entity_id,
        'charge_id' => $charge->id,
        'invoiceable_type' => 'Charge',
        'invoiceable_id' => $charge->id,
        'amount_cents' => 100,
        'precise_amount_cents' => '100.5',
        'amount_currency' => 'EUR',
        'unit_amount_cents' => 100,
        'precise_unit_amount' => '1',
        'taxes_amount_cents' => 20,
        'taxes_precise_amount_cents' => '20.5',
        'taxes_rate' => 20.0,
        'units' => '1',
        'total_aggregated_units' => '1',
        'events_count' => 4,
        'properties' => [
            'from_datetime' => '2023-08-01 00:00:00',
            'to_datetime' => '2023-08-31 23:59:59',
            'charges_from_datetime' => '2023-08-01 00:00:00',
            'charges_to_datetime' => '2023-08-31 23:59:59',
        ],
        'grouped_by' => [],
    ], $feeOverrides));

    return [$fee, (new FeeSerializer($fee, $options))->serialize()];
}

it('serializes a charge fee with literal snake_case keys', function (): void {
    [$fee, $payload] = serializedChargeFee();

    expect($payload['lago_id'])->toBe($fee->id)
        ->and($payload['lago_charge_id'])->toBe($fee->charge_id)
        ->and($payload['lago_charge_filter_id'])->toBeNull()
        ->and($payload['lago_fixed_charge_id'])->toBeNull()
        ->and($payload['lago_invoice_id'])->toBe($fee->invoice_id)
        ->and($payload['lago_true_up_fee_id'])->toBeNull()
        ->and($payload['lago_true_up_parent_fee_id'])->toBeNull()
        ->and($payload['lago_original_fee_id'])->toBeNull()
        ->and($payload['lago_subscription_id'])->toBe($fee->subscription_id)
        ->and($payload['external_subscription_id'])->toBe('sub-fee-1')
        ->and($payload['external_customer_id'])->toBeString()
        ->and($payload['item'])->toBe([
            'type' => 'charge',
            'code' => 'api',
            'name' => 'API',
            'description' => 'API calls',
            // Rails Fee#invoice_name: the charge's own invoice_display_name
            // (factory-set) wins; a null one falls back to the metric name.
            'invoice_display_name' => $fee->charge->invoice_display_name,
            'filters' => null,
            'filter_invoice_display_name' => null,
            // Rails Fee#item_id: the BILLABLE METRIC id on charge fees.
            'lago_item_id' => $fee->charge->billable_metric_id,
            'item_type' => 'BillableMetric',
            // grouped_by asserted below — it serializes as a JSON object.
            'grouped_by' => $payload['item']['grouped_by'],
        ])
        // grouped_by serializes as a JSON OBJECT ({} — Rails jsonb), not [].
        ->and(json_encode($payload['item']['grouped_by']))->toBe('{}')
        ->and($payload['pay_in_advance'])->toBeFalse()
        ->and($payload['invoiceable'])->toBeTrue()
        ->and($payload['amount_cents'])->toBe(100)
        ->and($payload['amount_currency'])->toBe('EUR')
        // Rails serializes BigDecimal attributes as fixed-notation strings.
        ->and($payload['precise_amount'])->toBe('1.005')
        ->and($payload['taxes_amount_cents'])->toBe(20)
        ->and($payload['taxes_precise_amount'])->toBe('0.205')
        ->and($payload['taxes_rate'])->toBe(20.0)
        // BcNumeric scale 0 — Rails emits the BigDecimal as to_s("F") string.
        ->and($payload['total_aggregated_units'])->toBe('1.0')
        ->and($payload['total_amount_cents'])->toBe(120)
        ->and($payload['total_amount_currency'])->toBe('EUR')
        ->and($payload['units'])->toBe('1.0')
        ->and($payload['events_count'])->toBe(4)
        ->and($payload['payment_status'])->toBe('pending')
        ->and($payload['self_billed'])->toBeFalse()
        ->and($payload['pricing_unit_details'])->toBeNull()
        ->and($payload['presentation_breakdowns'])->toBe([])
        // date boundaries from the fee properties (charges_* keys on a
        // charge fee), ISO8601.
        ->and($payload['from_date'])->toBe('2023-08-01T00:00:00+00:00')
        ->and($payload['to_date'])->toBe('2023-08-31T23:59:59+00:00')
        ->and($payload['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and(array_key_exists('applied_taxes', $payload))->toBeFalse();
})->group('ledger:ser:V1.FeeSerializer');

it('serializes a subscription fee with the plan item', function (): void {
    $organization = App\Models\Organization::factory()->create();
    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $plan = App\Models\Plan::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'pro',
        'name' => 'Pro',
        'invoice_display_name' => null,
        'pay_in_advance' => true,
    ]);
    $subscription = App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
    ]);
    $fee = Fee::factory()->subscriptionFee()->create([
        'invoice_id' => App\Models\Invoice::factory()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
        ])->id,
        'subscription_id' => $subscription->id,
        'organization_id' => $organization->id,
        'billing_entity_id' => $customer->billing_entity_id,
        'amount_cents' => 1000,
        'amount_currency' => 'EUR',
    ]);

    $payload = (new FeeSerializer($fee))->serialize();

    expect($payload['item']['type'])->toBe('subscription')
        ->and($payload['item']['code'])->toBe('pro')
        ->and($payload['item']['name'])->toBe('Pro')
        ->and($payload['item']['item_type'])->toBe('Subscription')
        // Rails Fee#item_id: the SUBSCRIPTION id on subscription fees (not
        // the plan's), and invoice_display_name falls back to the
        // subscription's own name.
        ->and($payload['item']['lago_item_id'])->toBe($subscription->id)
        // subscription fees carry the plan's pay_in_advance.
        ->and($payload['pay_in_advance'])->toBeTrue();
})->group('ledger:ser:V1.FeeSerializer');

it('links a true-up fee to its parent and emits the boundary dates', function (): void {
    [$fee] = serializedChargeFee();
    $organization = App\Models\Organization::factory()->create();
    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $plan = App\Models\Plan::factory()->create(['organization_id' => $organization->id]);
    $subscription = App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
    ]);

    $trueUp = Fee::factory()->chargeFee()->create([
        'subscription_id' => $subscription->id,
        'organization_id' => $organization->id,
        'billing_entity_id' => $customer->billing_entity_id,
        'amount_cents' => 300,
        'true_up_parent_fee_id' => $fee->id,
        'events_count' => 0,
        'units' => '1',
    ]);

    // lago_true_up_fee_id is resolved from the parent side (a fee whose
    // true_up_parent_fee_id points at the serialized fee).
    $payload = (new FeeSerializer($fee))->serialize();

    expect($payload['lago_true_up_fee_id'])->toBe($trueUp->id);
})->group('ledger:ser:V1.FeeSerializer');
