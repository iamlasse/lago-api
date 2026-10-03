<?php

declare(strict_types=1);

require_once __DIR__.'/WalletsTestHelpers.php';

use App\Models\BillableMetric;
use App\Support\CurrentContext;
use App\Services\Wallets\UpdateService;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;

beforeEach(function (): void {
    CurrentContext::reset();
    CurrentContext::$source = 'api';
});

it('updates the editable attributes and locks_version', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer, ['code' => 'promo', 'priority' => 10]);

    $result = UpdateService::call(wallet: $wallet, params: [
        'name' => 'Renamed',
        'code' => 'renamed',
        'priority' => 3,
        'expiration_at' => now()->addYear()->startOfDay()->toISOString(),
        'purchase_order_number' => 'PO-9',
        'invoice_requires_successful_payment' => true,
        'paid_top_up_min_amount_cents' => 100,
        'paid_top_up_max_amount_cents' => 5000,
    ]);

    expect($result->success())->toBeTrue();

    $wallet->refresh();

    expect($wallet->name)->toBe('Renamed')
        ->and($wallet->code)->toBe('renamed')
        ->and($wallet->priority)->toBe(3)
        ->and($wallet->purchase_order_number)->toBe('PO-9')
        ->and($wallet->invoice_requires_successful_payment)->toBeTrue()
        ->and($wallet->paid_top_up_min_amount_cents)->toBe(100)
        ->and($wallet->paid_top_up_max_amount_cents)->toBe(5000)
        ->and($wallet->lock_version)->toBe(1);
})->group('ledger:svc:Wallets.UpdateService');

it('refuses to update a terminated wallet', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = terminatedWalletFor($customer);

    $result = UpdateService::call(wallet: $wallet, params: ['name' => 'New']);

    expect($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages)->toBe(['wallet_id' => ['wallet_is_terminated']]);
})->group('ledger:svc:Wallets.UpdateService');

it('fails on an invalid expiration date and unknown billing entity', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer);

    $past = UpdateService::call(wallet: $wallet, params: ['expiration_at' => 'not-a-date']);
    expect($past->getError()->messages)->toBe(['expiration_at' => ['invalid_date']]);

    $past2 = UpdateService::call(wallet: $wallet, params: ['expiration_at' => now()->subDay()->toISOString()]);
    expect($past2->getError()->messages)->toBe(['expiration_at' => ['invalid_date']]);

    $unknown = UpdateService::call(wallet: $wallet, params: ['billing_entity_code' => 'nope']);
    expect($unknown->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($unknown->getError()->resource)->toBe('billing_entity');
})->group('ledger:svc:Wallets.UpdateService');

it('validates limitations and replaces wallet targets when the identifiers key is sent', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer);

    $metricA = BillableMetric::factory()->create(['organization_id' => $organization->id, 'code' => 'api_calls']);
    $metricB = BillableMetric::factory()->create(['organization_id' => $organization->id, 'code' => 'storage']);

    UpdateService::call(wallet: $wallet, params: [
        'applies_to' => ['fee_types' => ['charge'], 'billable_metric_codes' => ['api_calls']],
    ]);

    expect($wallet->walletTargets()->pluck('billable_metric_id')->all())->toBe([$metricA->id])
        ->and($wallet->refresh()->allowed_fee_types)->toBe(['charge']);

    // Sending the key again replaces the targets.
    UpdateService::call(wallet: $wallet, params: [
        'applies_to' => ['billable_metric_codes' => ['storage', 'api_calls']],
    ]);

    expect($wallet->walletTargets()->count())->toBe(2);

    // Omitting applies_to leaves the targets untouched.
    UpdateService::call(wallet: $wallet, params: ['name' => 'Keep targets']);

    expect($wallet->walletTargets()->count())->toBe(2);

    // Sending an empty array clears them.
    UpdateService::call(wallet: $wallet, params: ['applies_to' => ['billable_metric_codes' => []]]);

    expect($wallet->walletTargets()->count())->toBe(0);
})->group('ledger:svc:Wallets.UpdateService');

it('rejects unknown billable metric codes on update', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer);

    $result = UpdateService::call(wallet: $wallet, params: [
        'applies_to' => ['billable_metric_codes' => ['missing']],
    ]);

    expect($result->getError())->toBeInstanceOf(ValidationFailure::class)
        // Rails: UpdateService returns right after
        // ValidateLimitationsService fails — the messages carry the raw
        // limitation errors (no applies_to wrapper on the update path).
        ->and($result->getError()->messages)->toBe(['billable_metrics' => ['invalid_identifier']]);
})->group('ledger:svc:Wallets.UpdateService');

it('flags the customer for refresh when refresh-relevant attributes change', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer);
    expect($customer->refresh()->awaiting_wallet_refresh)->toBeFalse();

    UpdateService::call(wallet: $wallet, params: ['name' => 'irrelevant change']);
    expect($customer->refresh()->awaiting_wallet_refresh)->toBeFalse();

    UpdateService::call(wallet: $wallet, params: ['code' => 'new_code']);
    expect($customer->refresh()->awaiting_wallet_refresh)->toBeTrue();
})->group('ledger:svc:Wallets.UpdateService');

it('updates the metadata with replace and merge semantics', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer);

    UpdateService::call(wallet: $wallet, params: ['metadata' => ['a' => '1']]);
    expect($wallet->metadata()->first()?->value)->toBe(['a' => '1']);

    UpdateService::call(wallet: $wallet, params: ['metadata' => ['b' => '2']], partialMetadata: true);
    expect($wallet->metadata()->first()?->value)->toBe(['a' => '1', 'b' => '2']);

    UpdateService::call(wallet: $wallet, params: ['metadata' => null]);
    expect($wallet->metadata()->exists())->toBeFalse();
})->group('ledger:svc:Wallets.UpdateService');
