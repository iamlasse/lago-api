<?php

declare(strict_types=1);

require_once __DIR__.'/../../../Unit/Services/Wallets/WalletsTestHelpers.php';

use App\Models\Wallet;
use App\Serializers\V1\WalletSerializer;

it('serializes the wallet payload statement for statement', function (): void {
    [$organization, $customer] = walletSetup();

    $wallet = Wallet::factory()->forCustomer($customer)->create([
        'name' => 'Promo',
        'code' => 'promo',
        'rate_amount' => '1.5',
        'credits_balance' => '12.34000',
        'balance_cents' => 1234,
        'ongoing_balance_cents' => 1100,
        'ongoing_usage_balance_cents' => 134,
        'consumed_credits' => '0.66000',
        'credits_ongoing_balance' => '11.00000',
        'credits_ongoing_usage_balance' => '1.34000',
        'purchase_order_number' => 'PO-1',
        'invoice_requires_successful_payment' => true,
        'paid_top_up_min_amount_cents' => 100,
        'paid_top_up_max_amount_cents' => 5000,
        'priority' => 3,
        'allowed_fee_types' => ['charge'],
    ]);

    $payload = (new WalletSerializer($wallet))->serialize();

    expect($payload['lago_id'])->toBe($wallet->id)
        ->and($payload['lago_customer_id'])->toBe($customer->id)
        ->and($payload['external_customer_id'])->toBe($customer->external_id)
        ->and($payload['billing_entity_code'])->toBe($wallet->resolvedBillingEntity()->code)
        ->and($payload['status'])->toBe('active')
        ->and($payload['currency'])->toBe('EUR')
        ->and($payload['name'])->toBe('Promo')
        ->and($payload['code'])->toBe('promo')
        ->and($payload['purchase_order_number'])->toBe('PO-1')
        ->and($payload['rate_amount'])->toBe('1.50000')
        ->and($payload['credits_balance'])->toBe('12.34000')
        ->and($payload['credits_ongoing_balance'])->toBe('11.00000')
        ->and($payload['credits_ongoing_usage_balance'])->toBe('1.34000')
        ->and($payload['balance_cents'])->toBe(1234)
        ->and($payload['ongoing_balance_cents'])->toBe(1100)
        ->and($payload['ongoing_usage_balance_cents'])->toBe(134)
        ->and($payload['consumed_credits'])->toBe('0.66000')
        ->and($payload['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($payload['expiration_at'])->toBeNull()
        ->and($payload['terminated_at'])->toBeNull()
        ->and($payload['invoice_requires_successful_payment'])->toBeTrue()
        ->and($payload['paid_top_up_min_amount_cents'])->toBe(100)
        ->and($payload['paid_top_up_max_amount_cents'])->toBe(5000)
        ->and($payload['priority'])->toBe(3)
        ->and($payload['payment_method'])->toBe([
            'payment_method_id' => null,
            'payment_method_type' => 'provider',
        ])
        ->and($payload['connections'])->toBeArray()->each(fn ($c) => null);
})->group('ledger:ser:V1.WalletSerializer');

it('includes the limitations payload when requested', function (): void {
    [$organization, $customer] = walletSetup();

    $metric = App\Models\BillableMetric::factory()->create(['organization_id' => $organization->id, 'code' => 'api_calls']);
    $wallet = Wallet::factory()->forCustomer($customer)->create([
        'allowed_fee_types' => ['charge', 'add_on'],
    ]);
    $wallet->walletTargets()->create(['billable_metric_id' => $metric->id, 'organization_id' => $organization->id]);

    $payload = (new WalletSerializer($wallet, ['includes' => ['limitations']]))->serialize();

    expect($payload['limitations'])->toBe([
        'applies_to' => [
            'fee_types' => ['charge', 'add_on'],
            'billable_metric_codes' => ['api_calls'],
        ],
    ]);

    $without = (new WalletSerializer($wallet))->serialize();

    expect($without)->not->toHaveKey('limitations');
})->group('ledger:ser:V1.WalletSerializer');

it('serializes the terminated status and exposes the metadata when present', function (): void {
    [$organization, $customer] = walletSetup();

    $wallet = Wallet::factory()->forCustomer($customer)->terminated()->create();

    $payload = (new WalletSerializer($wallet))->serialize();

    expect($payload['status'])->toBe('terminated')
        ->and($payload)->not->toHaveKey('metadata');

    App\Services\Metadata\UpdateItemService::callBang(
        owner: $wallet,
        value: ['env' => 'prod'],
        partial: false,
    );

    $withMetadata = (new WalletSerializer($wallet->refresh()))->serialize();

    expect($withMetadata['metadata'])->toBe(['metadata' => ['env' => 'prod']]);
})->group('ledger:ser:V1.WalletSerializer');
