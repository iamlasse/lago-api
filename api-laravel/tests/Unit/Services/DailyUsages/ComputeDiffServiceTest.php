<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\DailyUsage;
use App\Models\Subscription;
use App\Services\DailyUsages\ComputeDiffService;

uses()->group('ledger:svc:DailyUsages.ComputeDiffService');

/**
 * Port of Rails' spec/services/daily_usages/compute_diff_service_spec.rb
 * (the core subtraction / taxes-proration scenarios).
 */
function diffUsagePayload(array $overrides = []): array
{
    return array_merge([
        'from_datetime' => '2024-10-01T00:00:00Z',
        'to_datetime' => '2024-10-31T23:59:59Z',
        'issuing_date' => '2024-11-01',
        'currency' => 'EUR',
        'amount_cents' => 300,
        'total_amount_cents' => 330,
        'taxes_amount_cents' => 30,
        'charges_usage' => [
            'charges_usage' => [
                [
                    'units' => '3.0',
                    'total_aggregated_units' => '3.0',
                    'events_count' => 3,
                    'amount_cents' => 300,
                    'amount_currency' => 'EUR',
                    'charge' => ['lago_id' => 'charge-1', 'code' => 'api_calls'],
                    'billable_metric' => ['lago_id' => 'bm-1', 'code' => 'api_calls'],
                    'filters' => [],
                    'grouped_usage' => [],
                    'presentation_breakdowns' => [],
                ],
            ],
        ],
    ], $overrides);
}

function diffDailyUsage(array $usage, string $usageDate = '2024-10-21', ?Subscription $subscription = null): DailyUsage
{
    $customer = Customer::factory()->create();
    $subscription ??= Subscription::factory()->for($customer)->create();

    $dailyUsage = new DailyUsage([
        'organization_id' => $subscription->organization_id,
        'customer_id' => $subscription->customer_id,
        'subscription_id' => $subscription->id,
        'external_subscription_id' => $subscription->external_id,
        'refreshed_at' => '2024-10-21 00:00:00',
        'from_datetime' => '2024-10-01 00:00:00',
        'to_datetime' => '2024-10-31 23:59:59',
        'usage' => $usage,
        'usage_date' => $usageDate,
    ]);
    $dailyUsage->usage_diff = [];

    return $dailyUsage;
}

it('returns the full usage as the diff when there is no previous daily usage', function (): void {
    $usage = diffUsagePayload();
    $dailyUsage = diffDailyUsage($usage);

    $result = ComputeDiffService::call(dailyUsage: $dailyUsage);

    expect($result->usage_diff)->toBe($usage);
});

it('subtracts the previous snapshot charge by charge', function (): void {
    $previous = diffDailyUsage(diffUsagePayload([
        'amount_cents' => 250,
        'total_amount_cents' => 280,
        'charges_usage' => ['charges_usage' => [
            [
                'units' => '2.5',
                'total_aggregated_units' => '2.5',
                'events_count' => 2,
                'amount_cents' => 250,
                'amount_currency' => 'EUR',
                'charge' => ['lago_id' => 'charge-1', 'code' => 'api_calls'],
                'billable_metric' => ['lago_id' => 'bm-1', 'code' => 'api_calls'],
                'filters' => [],
                'grouped_usage' => [],
                'presentation_breakdowns' => [],
            ],
        ]],
    ]), '2024-10-20');

    $current = diffDailyUsage(diffUsagePayload());

    $result = ComputeDiffService::call(dailyUsage: $current, previousDailyUsage: $previous);

    $charges = $result->usage_diff['charges_usage']['charges_usage'];

    expect($charges[0]['units'])->toBe('0.5')
        ->and($charges[0]['events_count'])->toBe(1)
        ->and($charges[0]['amount_cents'])->toBe(50)
        ->and($result->usage_diff['amount_cents'])->toBe(50)
        // Common ratio 250/250 = 1 -> the full 30 previous taxes are deducted.
        ->and($result->usage_diff['taxes_amount_cents'])->toBe(0)
        ->and($result->usage_diff['total_amount_cents'])->toBe(50);
});

it('prorates the previous taxes over the common charges', function (): void {
    // Previous: A(100) + B(200) = 300 with 30 in taxes. Current only has A.
    $previousCharges = [
        [
            'units' => '1.0', 'total_aggregated_units' => '1.0', 'events_count' => 1,
            'amount_cents' => 100, 'amount_currency' => 'EUR',
            'charge' => ['lago_id' => 'charge-a', 'code' => 'a'],
            'billable_metric' => ['lago_id' => 'bm-a', 'code' => 'a'],
            'filters' => [], 'grouped_usage' => [], 'presentation_breakdowns' => [],
        ],
        [
            'units' => '2.0', 'total_aggregated_units' => '2.0', 'events_count' => 2,
            'amount_cents' => 200, 'amount_currency' => 'EUR',
            'charge' => ['lago_id' => 'charge-b', 'code' => 'b'],
            'billable_metric' => ['lago_id' => 'bm-b', 'code' => 'b'],
            'filters' => [], 'grouped_usage' => [], 'presentation_breakdowns' => [],
        ],
    ];

    $previous = diffDailyUsage(diffUsagePayload([
        'amount_cents' => 300,
        'total_amount_cents' => 330,
        'taxes_amount_cents' => 30,
        'charges_usage' => ['charges_usage' => $previousCharges],
    ]), '2024-10-20');

    $current = diffDailyUsage(diffUsagePayload([
        'amount_cents' => 100,
        'total_amount_cents' => 110,
        'taxes_amount_cents' => 10,
        'charges_usage' => ['charges_usage' => [$previousCharges[0]]],
    ]));

    $result = ComputeDiffService::call(dailyUsage: $current, previousDailyUsage: $previous);

    // Current charge A(100) minus previous A(100) -> 0; the taxes are
    // prorated by the common ratio (100/300) -> deduct 10 of 30 from the
    // current 10 -> 0.
    expect($result->usage_diff['amount_cents'])->toBe(0)
        ->and($result->usage_diff['taxes_amount_cents'])->toBe(0)
        ->and($result->usage_diff['total_amount_cents'])->toBe(0);
});

it('diffs the grouped usage and presentation breakdowns', function (): void {
    $previousCharge = [
        'units' => '2.0', 'total_aggregated_units' => '2.0', 'events_count' => 2,
        'amount_cents' => 200, 'amount_currency' => 'EUR',
        'charge' => ['lago_id' => 'charge-1', 'code' => 'api_calls'],
        'billable_metric' => ['lago_id' => 'bm-1', 'code' => 'api_calls'],
        'filters' => [],
        'grouped_usage' => [
            [
                'units' => '2.0', 'events_count' => 2, 'amount_cents' => 200,
                'grouped_by' => ['region' => 'eu'],
                'filters' => [], 'presentation_breakdowns' => [],
            ],
        ],
        'presentation_breakdowns' => [
            ['units' => '2.0', 'presentation_by' => ['region' => 'eu']],
        ],
    ];

    $currentCharge = json_decode(json_encode($previousCharge), true);
    $currentCharge['units'] = '3.0';
    $currentCharge['events_count'] = 3;
    $currentCharge['amount_cents'] = 300;
    $currentCharge['grouped_usage'][0]['units'] = '3.0';
    $currentCharge['grouped_usage'][0]['events_count'] = 3;
    $currentCharge['grouped_usage'][0]['amount_cents'] = 300;
    $currentCharge['presentation_breakdowns'][0]['units'] = '3.0';

    $previous = diffDailyUsage(diffUsagePayload([
        'charges_usage' => ['charges_usage' => [$previousCharge]],
    ]), '2024-10-20');
    $current = diffDailyUsage(diffUsagePayload([
        'amount_cents' => 300,
        'charges_usage' => ['charges_usage' => [$currentCharge]],
    ]));

    $result = ComputeDiffService::call(dailyUsage: $current, previousDailyUsage: $previous);

    $charge = $result->usage_diff['charges_usage']['charges_usage'][0];

    expect($charge['units'])->toBe('1.0')
        ->and($charge['grouped_usage'][0]['units'])->toBe('1.0')
        ->and($charge['grouped_usage'][0]['events_count'])->toBe(1)
        ->and($charge['grouped_usage'][0]['amount_cents'])->toBe(100)
        ->and($charge['presentation_breakdowns'][0]['units'])->toBe('1.0');
});

it('looks up the previous snapshot several days back in the same period', function (): void {
    $customer = Customer::factory()->create();
    $subscription = Subscription::factory()->for($customer)->create();

    $previous = diffDailyUsage(diffUsagePayload([
        'charges_usage' => ['charges_usage' => [
            [
                'units' => '1.0', 'total_aggregated_units' => '1.0', 'events_count' => 1,
                'amount_cents' => 100, 'amount_currency' => 'EUR',
                'charge' => ['lago_id' => 'charge-1', 'code' => 'api_calls'],
                'billable_metric' => ['lago_id' => 'bm-1', 'code' => 'api_calls'],
                'filters' => [], 'grouped_usage' => [], 'presentation_breakdowns' => [],
            ],
        ]],
    ]), '2024-10-17', $subscription);
    $previous->save();

    $current = diffDailyUsage(diffUsagePayload(), '2024-10-21', $subscription);

    $result = ComputeDiffService::call(dailyUsage: $current);

    expect($result->usage_diff['charges_usage']['charges_usage'][0]['amount_cents'])->toBe(200)
        ->and($result->usage_diff['charges_usage']['charges_usage'][0]['units'])->toBe('2.0');
});

it('ignores previous snapshots from another billing period', function (): void {
    $customer = Customer::factory()->create();
    $subscription = Subscription::factory()->for($customer)->create();

    $previous = diffDailyUsage(diffUsagePayload(), '2024-09-20', $subscription);
    $previous->from_datetime = '2024-09-01 00:00:00';
    $previous->to_datetime = '2024-09-30 23:59:59';
    $previous->save();

    $current = diffDailyUsage(diffUsagePayload(), '2024-10-21', $subscription);

    $result = ComputeDiffService::call(dailyUsage: $current);

    expect($result->usage_diff)->toBe($current->usage);
});
