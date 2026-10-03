<?php

declare(strict_types=1);

require_once __DIR__.'/WalletsTestHelpers.php';

use App\Models\Wallet;
use App\Models\Customer;
use App\Enums\WalletStatus;
use App\Models\BillableMetric;
use App\Support\CurrentContext;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Queue;
use App\Services\Wallets\CreateService;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;

beforeEach(function (): void {
    CurrentContext::reset();
    CurrentContext::$source = 'api';
});

function walletCreateArgs(Customer $customer, array $overrides = []): array
{
    return array_merge([
        'organization_id' => $customer->organization_id,
        'customer' => $customer,
        'name' => 'Promo Wallet',
        'rate_amount' => '1.5',
        'currency' => 'EUR',
    ], $overrides);
}

it('creates an active wallet with a code generated from the name', function (): void {
    [, $customer] = walletSetup();

    $result = CreateService::call(params: walletCreateArgs($customer));

    expect($result->success())->toBeTrue();

    $wallet = $result->wallet;

    expect($wallet->id)->not->toBeEmpty()
        ->and($wallet->customer_id)->toBe($customer->id)
        ->and($wallet->organization_id)->toBe($customer->organization_id)
        ->and($wallet->name)->toBe('Promo Wallet')
        ->and($wallet->code)->toBe('promo_wallet')
        ->and($wallet->statusEnum())->toBe(WalletStatus::Active)
        ->and($wallet->rate_amount)->toBe('1.50000')
        ->and($wallet->balance_currency)->toBe('EUR')
        ->and($wallet->consumed_amount_currency)->toBe('EUR')
        ->and($wallet->priority)->toBe(Wallet::LOWEST_PRIORITY)
        ->and($wallet->traceable)->toBeTrue();

    expect(Wallet::query()->find($wallet->id))->not->toBeNull();
})->group('ledger:svc:Wallets.CreateService');

it('keeps an explicit code and suffixes a taken generated one', function (): void {
    [, $customer] = walletSetup();

    $first = CreateService::call(params: walletCreateArgs($customer, ['code' => 'custom_code']));
    expect($first->wallet->code)->toBe('custom_code');

    $generated = CreateService::call(params: walletCreateArgs($customer, ['name' => 'Promo Wallet', 'code' => null]));
    expect($generated->wallet->code)->toBe('promo_wallet');

    $conflict = CreateService::call(params: walletCreateArgs($customer, ['name' => 'Custom Code', 'code' => null]));
    expect($conflict->wallet->code)->toStartWith('custom_code_');
})->group('ledger:svc:Wallets.CreateService');

it('propagates the currency to a currency-less customer', function (): void {
    [, $customer] = walletSetup();
    $customer->currency = null;
    $customer->save();

    $result = CreateService::call(params: walletCreateArgs($customer));

    expect($result->success())->toBeTrue()
        ->and($customer->refresh()->currency)->toBe('EUR');
})->group('ledger:svc:Wallets.CreateService');

it('marks the wallet traceable only when every active wallet is traceable', function (): void {
    [, $customer] = walletSetup();

    Wallet::factory()->forCustomer($customer)->create(['traceable' => false]);

    $result = CreateService::call(params: walletCreateArgs($customer));

    expect($result->wallet->traceable)->toBeFalse();
})->group('ledger:svc:Wallets.CreateService');

it('creates wallet targets from applies_to billable_metric_codes', function (): void {
    $organization = CurrentContext::$organization = App\Models\Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $metricA = BillableMetric::factory()->create(['organization_id' => $organization->id, 'code' => 'api_calls']);
    $metricB = BillableMetric::factory()->create(['organization_id' => $organization->id, 'code' => 'storage']);

    $result = CreateService::call(params: walletCreateArgs($customer, [
        'applies_to' => ['fee_types' => ['charge'], 'billable_metric_codes' => ['api_calls', 'storage']],
    ]));

    expect($result->success())->toBeTrue()
        ->and($result->billable_metric_identifiers)->toBe(['api_calls', 'storage'])
        ->and($result->wallet->walletTargets()->count())->toBe(2)
        ->and($result->wallet->walletTargets()->pluck('billable_metric_id')->sort()->values()->all())
        ->toBe(collect([$metricA->id, $metricB->id])->sort()->values()->all())
        ->and($result->wallet->allowed_fee_types)->toBe(['charge']);
})->group('ledger:svc:Wallets.CreateService');

it('rejects unknown billable metric codes and invalid fee types', function (): void {
    [, $customer] = walletSetup();

    $unknownMetric = CreateService::call(params: walletCreateArgs($customer, [
        'applies_to' => ['billable_metric_codes' => ['nope']],
    ]));

    expect($unknownMetric->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($unknownMetric->getError()->messages)->toBe(['applies_to' => ['invalid_limitations']]);

    $invalidFeeTypes = CreateService::call(params: walletCreateArgs($customer, [
        'applies_to' => ['fee_types' => ['crypto']],
    ]));

    // Rails overwrites the outer result: ValidateLimitationsService writes
    // {allowed_fee_types: [...]}, then ValidateService re-fails with the
    // wraps-it-in-applies_to error — the final payload matches Rails.
    expect($invalidFeeTypes->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($invalidFeeTypes->getError()->messages)->toBe(['applies_to' => ['invalid_limitations']]);
})->group('ledger:svc:Wallets.CreateService');

it('enforces the maximum number of wallets per customer', function (): void {
    [, $customer] = walletSetup();

    for ($i = 0; $i < 6; $i++) {
        Wallet::factory()->forCustomer($customer)->create();
    }

    $result = CreateService::call(params: walletCreateArgs($customer));

    expect($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages)->toBe(['customer' => ['wallet_limit_reached']]);
})->group('ledger:svc:Wallets.CreateService');

it('fails on a missing customer or unknown billing entity', function (): void {
    [, $customer] = walletSetup();

    $noCustomer = CreateService::call(params: walletCreateArgs($customer, ['customer' => null]));
    expect($noCustomer->getError()->messages)->toBe(['customer' => ['customer_not_found']]);

    $unknownEntity = CreateService::call(params: walletCreateArgs($customer, ['billing_entity_code' => 'nope']));
    expect($unknownEntity->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($unknownEntity->getError()->resource)->toBe('billing_entity');
})->group('ledger:svc:Wallets.CreateService');

it('fails on an invalid expiration date or invalid credits amounts', function (): void {
    [, $customer] = walletSetup();

    $past = CreateService::call(params: walletCreateArgs($customer, ['expiration_at' => now()->subDay()->toISOString()]));
    expect($past->getError()->messages)->toBe(['expiration_at' => ['invalid_date']]);

    $invalid = CreateService::call(params: walletCreateArgs($customer, ['paid_credits' => 'not-a-number']));
    expect($invalid->getError()->messages)->toBe(['paid_credits' => ['invalid_paid_credits', 'invalid_amount']]);
})->group('ledger:svc:Wallets.CreateService');

it('rejects paid credits rounding to zero and amounts below the top-up minimum', function (): void {
    [, $customer] = walletSetup();

    $roundsToZero = CreateService::call(params: walletCreateArgs($customer, ['rate_amount' => '1', 'paid_credits' => '0.00001']));
    expect($roundsToZero->getError()->messages)->toBe(['paid_credits' => ['amount_rounds_to_zero']]);

    $belowMinimum = CreateService::call(params: walletCreateArgs($customer, [
        'rate_amount' => '1',
        'paid_top_up_min_amount_cents' => 1000,
        'paid_credits' => '5',
    ]));

    expect($belowMinimum->getError()->messages)->toBe(['paid_credits' => ['amount_below_minimum']]);

    $aboveMinimum = CreateService::call(params: walletCreateArgs($customer, [
        'rate_amount' => '1',
        'paid_top_up_min_amount_cents' => 1000,
        'paid_credits' => '50',
    ]));
    expect($aboveMinimum->success())->toBeTrue();
})->group('ledger:svc:Wallets.CreateService');

it('creates the item metadata when provided and does not schedule the top-up seam', function (): void {
    Queue::fake();

    [, $customer] = walletSetup();

    $result = CreateService::call(params: walletCreateArgs($customer, [
        'metadata' => ['key' => 'env', 'value' => 'prod'],
        'granted_credits' => '10',
    ]));

    expect($result->success())->toBeTrue()
        ->and($result->wallet->metadata()->exists())->toBeTrue()
        // Rails stores the {key, value} payload object verbatim in the
        // item_metadata jsonb value.
        ->and($result->wallet->metadata()->first()?->value)->toBe(['key' => 'env', 'value' => 'prod']);

    // THE INTEGRATION SEAM: the wallet-creation top-up (granted_credits) is
    // not scheduled yet — no wallet transaction must exist.
    expect(WalletTransaction::query()->where('wallet_id', $result->wallet->id)->count())->toBe(0);
})->group('ledger:svc:Wallets.CreateService');
