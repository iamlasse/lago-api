<?php

declare(strict_types=1);

require_once __DIR__.'/../../../Unit/Services/Wallets/WalletsTestHelpers.php';

use App\Models\Wallet;
use App\Enums\WalletTransactionType;
use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionCreditStatus;
use App\Serializers\V1\WalletTransactionSerializer;

it('serializes the wallet transaction payload statement for statement', function (): void {
    [$organization, $customer] = walletSetup();

    $wallet = Wallet::factory()->forCustomer($customer)->create(['purchase_order_number' => 'WALLET-PO']);
    $invoice = App\Models\Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);

    $transaction = App\Models\WalletTransaction::factory()->forWallet($wallet)->create([
        'transaction_type' => WalletTransactionType::Inbound,
        'transaction_status' => WalletTransactionCreditStatus::Granted,
        'status' => WalletTransactionStatus::Settled,
        'amount' => '10.00000',
        'credit_amount' => '10.00000',
        'settled_at' => now()->startOfSecond(),
        'invoice_id' => $invoice->id,
        'remaining_amount_cents' => 400,
        'invoice_requires_successful_payment' => true,
        'metadata' => [['key' => 'ref', 'value' => 'abc']],
        'name' => 'Top up',
        'purchase_order_number' => null,
    ]);

    $payload = (new WalletTransactionSerializer($transaction))->serialize();

    expect($payload['lago_id'])->toBe($transaction->id)
        ->and($payload['lago_wallet_id'])->toBe($wallet->id)
        ->and($payload['lago_invoice_id'])->toBe($invoice->id)
        ->and($payload['lago_credit_note_id'])->toBeNull()
        ->and($payload['lago_voided_invoice_id'])->toBeNull()
        ->and($payload['billing_entity_code'])->toBe($wallet->resolvedBillingEntity()->code)
        ->and($payload['status'])->toBe('settled')
        ->and($payload['source'])->toBe('manual')
        ->and($payload['transaction_status'])->toBe('granted')
        ->and($payload['transaction_type'])->toBe('inbound')
        ->and($payload['amount'])->toBe('10.00000')
        ->and($payload['credit_amount'])->toBe('10.00000')
        ->and($payload['remaining_amount_cents'])->toBe(400)
        // remaining_amount_cents / subunit / rate_amount
        ->and($payload['remaining_credit_amount'])->toBe('4.000000000000000')
        ->and($payload['priority'])->toBe(50)
        // Falls back to the wallet's purchase order number.
        ->and($payload['purchase_order_number'])->toBe('WALLET-PO')
        ->and($payload['settled_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($payload['failed_at'])->toBeNull()
        ->and($payload['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($payload['invoice_requires_successful_payment'])->toBeTrue()
        ->and($payload['metadata'])->toBe([['key' => 'ref', 'value' => 'abc']])
        ->and($payload['name'])->toBe('Top up')
        ->and($payload['payment_method'])->toBe([
            'payment_method_id' => null,
            'payment_method_type' => 'provider',
        ])
        ->and($payload)->not->toHaveKey('wallet');
})->group('ledger:ser:V1.WalletTransactionSerializer');

it('includes the wallet when requested', function (): void {
    [$organization, $customer] = walletSetup();

    $wallet = Wallet::factory()->forCustomer($customer)->create(['code' => 'payload_wallet']);
    $transaction = App\Models\WalletTransaction::factory()->forWallet($wallet)->create();

    $payload = (new WalletTransactionSerializer($transaction, ['includes' => ['wallet']]))->serialize();

    expect($payload['wallet']['lago_id'])->toBe($wallet->id)
        ->and($payload['wallet']['code'])->toBe('payload_wallet');
})->group('ledger:ser:V1.WalletTransactionSerializer');
