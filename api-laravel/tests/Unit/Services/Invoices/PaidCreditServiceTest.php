<?php

declare(strict_types=1);

require_once __DIR__.'/../Wallets/WalletsTestHelpers.php';

uses()->group(
    'ledger:svc:Invoices.PaidCreditService',
    'ledger:svc:Fees.PaidCreditService',
    'ledger:job:BillPaidCreditJob',
);

use App\Models\Fee;
use App\Enums\FeeType;
use App\Models\Wallet;
use App\Models\Invoice;
use App\Models\Customer;
use App\Enums\InvoiceType;
use App\Enums\InvoiceStatus;
use App\Jobs\BillPaidCreditJob;
use App\Enums\WalletTransactionCreditStatus;
use App\Services\Invoices\PaidCreditService;
use App\Services\WalletTransactions\CreateFromParamsService;

/**
 * Port of Rails' spec/services/invoices/paid_credit_service_spec.rb (the
 * scenarios the port supports) — a purchased wallet transaction is billed
 * onto its own credit invoice and settled by BillPaidCreditJob.
 */
function purchasedTransaction(Customer $customer, Wallet $wallet): App\Models\WalletTransaction
{
    $result = CreateFromParamsService::call(
        organization: $customer->organization,
        params: [
            'wallet_id' => $wallet->id,
            'paid_credits' => '10',
            'name' => 'Pack 10',
        ],
    );

    expect($result->success())->toBeTrue();

    return $result->wallet_transactions[0];
}

it('bills a purchased transaction onto a finalized credit invoice', function (): void {
    Queue::fake();

    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer, ['traceable' => true]);
    $transaction = purchasedTransaction($customer, $wallet);

    expect($transaction->transactionStatusEnum())->toBe(WalletTransactionCreditStatus::Purchased)
        ->and($transaction->statusEnum()->label())->toBe('pending')
        ->and($transaction->invoice_id)->toBeNull();

    $result = PaidCreditService::call(
        walletTransaction: $transaction,
        timestamp: now()->getTimestamp(),
    );

    expect($result->success())->toBeTrue();

    $invoice = $result->invoice;

    expect($invoice->invoice_type)->toBe(InvoiceType::Credit)
        ->and($invoice->statusEnum())->toBe(InvoiceStatus::Finalized)
        ->and($invoice->fees_amount_cents)->toBe(1000)
        ->and($invoice->total_amount_cents)->toBe(1000)
        ->and($invoice->currency)->toBe('EUR');

    $fees = $invoice->fees()->get();

    expect(count($fees))->toBe(1);

    $fee = $fees[0];

    expect($fee->typeEnum())->toBe(FeeType::Credit)
        ->and($fee->invoiceable_type)->toBe('WalletTransaction')
        ->and((int) $fee->amount_cents)->toBe(1000)
        ->and(App\Support\MoneyMath::compare((string) $fee->units, '10'))->toBe(0)
        ->and((int) $fee->taxes_amount_cents)->toBe(0);

    expect((int) $transaction->fresh()->invoice_id)->toBe((int) $invoice->id);
});

it('reuses the persisted invoice on retry instead of billing twice', function (): void {
    Queue::fake();

    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer, ['traceable' => true]);
    $transaction = purchasedTransaction($customer, $wallet);

    $first = PaidCreditService::call(walletTransaction: $transaction, timestamp: now()->getTimestamp());
    expect($first->success())->toBeTrue();

    // BillPaidCreditJob retry semantics: an invoice was created but the
    // process failed — the retry reuses it without duplicating the fee.
    (new BillPaidCreditJob($transaction, now()->getTimestamp(), $first->invoice))->handle();

    $invoice = $first->invoice->fresh();

    expect($invoice->fees()->where('invoiceable_type', 'WalletTransaction')->count())->toBe(1)
        ->and(Invoice::query()->where('customer_id', $customer->id)->where('invoice_type', InvoiceType::Credit->value)->count())->toBe(1);
});

it('settles a pending purchased transaction when dispatched directly', function (): void {
    Queue::fake();

    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer, ['traceable' => true]);
    $transaction = purchasedTransaction($customer, $wallet);

    (new BillPaidCreditJob($transaction, now()->getTimestamp()))->handle();

    $invoice = $transaction->fresh()->invoice;

    expect($invoice)->not->toBeNull()
        ->and($invoice->statusEnum())->toBe(InvoiceStatus::Finalized)
        ->and($invoice->total_amount_cents)->toBe(1000);
});
