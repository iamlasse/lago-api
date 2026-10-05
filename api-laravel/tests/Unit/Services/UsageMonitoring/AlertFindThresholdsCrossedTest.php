<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\UsageMonitoring\Alert;
use App\Models\UsageMonitoring\AlertThreshold;

/**
 * Port of spec/models/usage_monitoring/alert_spec.rb (threshold-crossing
 * matrix) — find_thresholds_crossed increasing/decreasing with one-time and
 * recurring thresholds.
 */
function alertWithThresholds(array $thresholds, string $direction = 'increasing', string $previousValue = '0'): Alert
{
    $organization = Organization::factory()->create();

    $alert = Alert::factory()->forSubscription('sub-1')->create([
        'organization_id' => $organization->id,
        'alert_type' => 'current_usage_amount',
        'direction' => $direction,
        'previous_value' => $previousValue,
    ]);

    foreach ($thresholds as $threshold) {
        AlertThreshold::query()->create($threshold + [
            'organization_id' => $organization->id,
            'usage_monitoring_alert_id' => $alert->id,
        ]);
    }

    $alert->setRelation('thresholds', $alert->thresholds()->get());

    return $alert;
}

it('finds crossed one-time thresholds when increasing', function (): void {
    $alert = alertWithThresholds([
        ['value' => '100', 'recurring' => false],
        ['value' => '500', 'recurring' => false],
        ['value' => '1000', 'recurring' => false],
    ]);

    expect($alert->findThresholdsCrossed('600'))->toBe(['100.0', '500.0'])
        ->and($alert->findThresholdsCrossed('100'))->toBe(['100.0'])
        ->and($alert->findThresholdsCrossed('50'))->toBe([])
        ->and($alert->findThresholdsCrossed('1500'))->toBe(['100.0', '500.0', '1000.0']);
});

it('finds recurring thresholds when increasing past them', function (): void {
    $alert = alertWithThresholds([
        ['value' => '100', 'recurring' => false],
        ['value' => '100', 'recurring' => true],
    ], previousValue: '100');

    // Recurring every 100 above the one-time 100: 200, 300...
    expect($alert->findThresholdsCrossed('250'))->toBe(['200.0'])
        ->and($alert->findThresholdsCrossed('400'))->toBe(['200.0', '300.0', '400.0'])
        ->and($alert->findThresholdsCrossed('150'))->toBe([]);
});

it('does not repeat the recurring step that equals a previous crossing', function (): void {
    $alert = alertWithThresholds([
        ['value' => '100', 'recurring' => true],
    ], previousValue: '250');

    expect($alert->findThresholdsCrossed('300'))->toBe(['300.0'])
        ->and($alert->findThresholdsCrossed('250'))->toBe([]);
});

it('finds crossed thresholds when decreasing', function (): void {
    $alert = alertWithThresholds([
        ['value' => '1000', 'recurring' => false],
        ['value' => '500', 'recurring' => false],
    ], direction: 'decreasing', previousValue: '1200');

    expect($alert->findThresholdsCrossed('500'))->toBe(['500.0', '1000.0'])
        ->and($alert->findThresholdsCrossed('700'))->toBe(['1000.0'])
        ->and($alert->findThresholdsCrossed('1200'))->toBe([]);
});

it('finds recurring thresholds when decreasing below them', function (): void {
    $alert = alertWithThresholds([
        ['value' => '100', 'recurring' => true],
    ], direction: 'decreasing', previousValue: '0');

    // decreasing from 0 with step 100: crosses -100, -200...
    expect($alert->findThresholdsCrossed('-250'))->toBe(['-200.0', '-100.0'])
        ->and($alert->findThresholdsCrossed('0'))->toBe([]);
});

it('formats crossed thresholds for the webhook payload', function (): void {
    $organization = Organization::factory()->create();

    $alert = Alert::factory()->forSubscription('sub-1')->create([
        'organization_id' => $organization->id,
        'alert_type' => 'current_usage_amount',
        'direction' => 'increasing',
        'previous_value' => '0',
    ]);

    AlertThreshold::query()->create([
        'organization_id' => $organization->id,
        'usage_monitoring_alert_id' => $alert->id,
        'code' => 'warn',
        'value' => '100',
        'recurring' => false,
    ]);
    AlertThreshold::query()->create([
        'organization_id' => $organization->id,
        'usage_monitoring_alert_id' => $alert->id,
        'code' => 'every',
        'value' => '100',
        'recurring' => true,
    ]);

    $alert->setRelation('thresholds', $alert->thresholds()->get());

    $crossed = $alert->findThresholdsCrossed('250');

    expect($crossed)->toBe(['100.0', '200.0']);

    $formatted = $alert->formattedCrossedThresholds($crossed);

    expect($formatted)->toBe([
        ['code' => 'warn', 'value' => '100.0', 'recurring' => false],
        ['code' => 'every', 'value' => '200.0', 'recurring' => true],
    ]);
});

it('resolves the value per alert type', function (): void {
    $organization = Organization::factory()->create();
    $lifetimeUsage = App\Models\LifetimeUsage::factory()->create([
        'organization_id' => $organization->id,
        'historical_usage_amount_cents' => 10,
        'invoiced_usage_amount_cents' => 20,
        'current_usage_amount_cents' => 30,
    ]);
    $wallet = App\Models\Wallet::factory()->create([
        'organization_id' => $organization->id,
        'balance_cents' => 1234,
        'credits_balance' => '7',
    ]);

    $currentUsageAmount = Alert::factory()->forSubscription('s')->create([
        'organization_id' => $organization->id,
        'alert_type' => 'current_usage_amount',
    ]);
    $currentUsageAmount->setRelation('thresholds', collect());

    $lifetimeUsageAmount = Alert::factory()->forSubscription('s')->create([
        'organization_id' => $organization->id,
        'alert_type' => 'lifetime_usage_amount',
    ]);
    $lifetimeUsageAmount->setRelation('thresholds', collect());

    $walletBalance = Alert::factory()->forSubscription('s')->create([
        'organization_id' => $organization->id,
        'alert_type' => 'wallet_balance_amount',
    ]);
    $walletBalance->setRelation('thresholds', collect());

    $walletCredits = Alert::factory()->forSubscription('s')->create([
        'organization_id' => $organization->id,
        'alert_type' => 'wallet_credits_balance',
    ]);
    $walletCredits->setRelation('thresholds', collect());

    expect((string) $currentUsageAmount->findValue(['amount_cents' => 99]))->toBe('99')
        ->and($lifetimeUsageAmount->findValue($lifetimeUsage))->toBe('60')
        ->and($walletBalance->findValue($wallet))->toBe('1234')
        ->and($walletCredits->findValue($wallet))->toBe('7');
});
