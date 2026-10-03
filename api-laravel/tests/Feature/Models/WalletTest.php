<?php

declare(strict_types=1);

use App\Models\Wallet;
use App\Models\Customer;
use App\Enums\WalletStatus;
use App\Models\Exceptions\StaleObjectError;

/**
 * Wallet model conventions: enum round-trips, the virtual currency
 * attribute, termination, scopes and validations.
 */
function walletModelSetup(): array
{
    $organization = App\Models\Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    return [$organization, $customer];
}

it('round-trips the status enum and the materialized defaults', function (): void {
    [, $customer] = walletModelSetup();

    $wallet = Wallet::factory()->forCustomer($customer)->create();

    expect($wallet->statusEnum())->toBe(WalletStatus::Active)
        ->and($wallet->isActive())->toBeTrue()
        ->and($wallet->isTerminated())->toBeFalse()
        ->and($wallet->rate_amount)->toBe('1.00000')
        ->and($wallet->credits_balance)->toBe('0.00000')
        ->and($wallet->balance_cents)->toBe(0)
        ->and($wallet->ongoing_balance_cents)->toBe(0)
        ->and($wallet->priority)->toBe(Wallet::LOWEST_PRIORITY)
        ->and($wallet->traceable)->toBeTrue()
        ->and($wallet->allowed_fee_types)->toBe([]);

    $wallet->status = WalletStatus::Terminated;
    $wallet->save();
    $wallet->refresh();

    expect($wallet->statusEnum())->toBe(WalletStatus::Terminated)
        ->and($wallet->statusEnum()?->label())->toBe('terminated');
})->group('ledger:model:Wallet');

it('exposes currency as a virtual attribute over the two currency columns', function (): void {
    [, $customer] = walletModelSetup();

    $wallet = new Wallet([
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'rate_amount' => '1.5',
        'status' => WalletStatus::Active,
    ]);

    expect($wallet->currency)->toBeNull();

    $wallet->currency = 'USD';

    expect($wallet->balance_currency)->toBe('USD')
        ->and($wallet->consumed_amount_currency)->toBe('USD')
        ->and($wallet->currency)->toBe('USD');

    $wallet->save();
    $wallet->refresh();

    expect($wallet->currency)->toBe('USD');
})->group('ledger:model:Wallet');

it('terminates with mark_as_terminated! keeping the first timestamp', function (): void {
    [, $customer] = walletModelSetup();

    $wallet = Wallet::factory()->forCustomer($customer)->create();

    $wallet->markAsTerminated();

    expect($wallet->refresh()->isTerminated())->toBeTrue()
        ->and($wallet->terminated_at)->not->toBeNull();

    $firstTerminatedAt = $wallet->terminated_at;

    $wallet->markAsTerminated(now()->addHour());

    expect($wallet->refresh()->terminated_at->equalTo($firstTerminatedAt))->toBeTrue();
})->group('ledger:model:Wallet');

it('scopes active / terminated / expired / with_positive_balance', function (): void {
    [, $customer] = walletModelSetup();

    $active = Wallet::factory()->forCustomer($customer)->create();
    $terminated = Wallet::factory()->forCustomer($customer)->terminated()->create();
    $expired = Wallet::factory()->forCustomer($customer)->create(['expiration_at' => now()->subDay()]);
    $positive = Wallet::factory()->forCustomer($customer)->create(['balance_cents' => 500]);

    expect($customer->wallets()->active()->count())->toBe(3)
        ->and($customer->wallets()->terminated()->count())->toBe(1)
        ->and(Wallet::query()->expired()->count())->toBe(1)
        ->and(Wallet::query()->expired()->first()->id)->toBe($expired->id)
        ->and(Wallet::query()->withPositiveBalance()->first()->id)->toBe($positive->id)
        ->and(Wallet::inApplicationOrder()->get()->last()->id)->toBe($positive->id);
})->group('ledger:model:Wallet');

it('validates rate amount, priority and paid top-up bounds', function (): void {
    [, $customer] = walletModelSetup();

    $wallet = Wallet::factory()->forCustomer($customer)->make([
        'rate_amount' => '0',
        'priority' => 51,
        'paid_top_up_min_amount_cents' => 500,
        'paid_top_up_max_amount_cents' => 100,
    ]);

    $errors = $wallet->validateAttributes();

    expect($errors['rate_amount'])->toBe(['must_be_greater_than_zero'])
        ->and($errors['priority'])->toBe(['not_included_in_list'])
        ->and($errors['paid_top_up_max_amount_cents'])->toBe(['must_be_greater_than_or_equal_min']);
})->group('ledger:model:Wallet');

it('validates one active wallet per (customer, code)', function (): void {
    [, $customer] = walletModelSetup();

    Wallet::factory()->forCustomer($customer)->create(['code' => 'promo']);

    // Another customer may reuse the code.
    $otherCustomer = Customer::factory()->create(['organization_id' => $customer->organization_id]);
    $other = Wallet::factory()->forCustomer($otherCustomer)->make(['code' => 'promo']);
    expect($other->validateAttributes())->toBe([]);

    // Same customer may not.
    $duplicate = Wallet::factory()->forCustomer($customer)->make(['code' => 'promo']);
    expect($duplicate->validateAttributes()['code'] ?? null)->toBe(['value_already_exist']);

    // Terminated wallets do not hold the code.
    Wallet::factory()->forCustomer($customer)->terminated()->create(['code' => 'old']);
    $fine = Wallet::factory()->forCustomer($customer)->make(['code' => 'old']);
    expect($fine->validateAttributes())->toBe([]);
})->group('ledger:model:Wallet');

it('casts allowed_fee_types through the Postgres array cast', function (): void {
    [, $customer] = walletModelSetup();

    $wallet = Wallet::factory()->forCustomer($customer)->create([
        'allowed_fee_types' => ['charge', 'add_on'],
    ]);

    $wallet->refresh();

    expect($wallet->allowed_fee_types)->toBe(['charge', 'add_on'])
        ->and($wallet->limitedFeeTypes())->toBeTrue();

    $plain = Wallet::factory()->forCustomer($customer)->create();
    expect($plain->limitedFeeTypes())->toBeFalse();
})->group('ledger:model:Wallet');

it('bumps lock_version and rejects stale saves (optimistic locking)', function (): void {
    [, $customer] = walletModelSetup();

    $wallet = Wallet::factory()->forCustomer($customer)->create();
    expect($wallet->lock_version)->toBe(0);

    $stale = Wallet::query()->findOrFail($wallet->id);

    $wallet->update(['name' => 'fresh']);

    expect(fn () => $stale->update(['name' => 'stale']))->toThrow(StaleObjectError::class);

    expect($wallet->refresh()->lock_version)->toBe(1)
        ->and($wallet->name)->toBe('fresh');

    $wallet->update(['name' => 'fresher']);
    expect($wallet->refresh()->lock_version)->toBe(2);
})->group('ledger:model:Wallet');
