<?php

declare(strict_types=1);

require_once __DIR__.'/../Services/Wallets/WalletsTestHelpers.php';

uses()->group('ledger:job:WalletTransactions.CreateJob');

use App\Models\Wallet;
use App\Enums\WalletStatus;
use Illuminate\Support\Facades\Queue;
use App\Services\Wallets\CreateService;
use App\Jobs\WalletTransactions\CreateJob;

/**
 * The wallet-creation top-up seam: Wallets::CreateService schedules the
 * initial paid/granted credits through WalletTransactions::CreateJob
 * (Rails' schedule_top_up), and the job creates the transactions through
 * WalletTransactions::CreateFromParamsService.
 */
it('schedules the initial top-up from wallet creation with the documented params', function (): void {
    Queue::fake();

    [, $customer] = walletSetup();

    $result = CreateService::call(params: [
        'organization_id' => $customer->organization_id,
        'customer' => $customer,
        'name' => 'Top-up wallet',
        'rate_amount' => '1',
        'currency' => 'EUR',
        'paid_credits' => '25',
        'granted_credits' => '5',
        'transaction_name' => 'Initial grant',
        'transaction_metadata' => [['key' => 'batch', 'value' => 'b1']],
        'ignore_paid_top_up_limits_on_creation' => true,
        'purchase_order_number' => 'PO-42',
    ]);

    expect($result->success())->toBeTrue((string) $result->getError()?->getMessage());

    $wallet = $result->wallet;

    Queue::assertPushed(CreateJob::class, function (CreateJob $job) use ($wallet, $customer): bool {
        $params = $job->params;

        return $job->organizationId === $customer->organization_id
            && $params['wallet_id'] === $wallet->id
            && $params['paid_credits'] === '25'
            && $params['granted_credits'] === '5'
            && $params['source'] === 'manual'
            && $params['name'] === 'Initial grant'
            && $params['metadata'] === [['key' => 'batch', 'value' => 'b1']]
            && $params['purchase_order_number'] === 'PO-42';
    });
});

it('does not schedule a top-up without credits', function (): void {
    Queue::fake();

    [, $customer] = walletSetup();

    CreateService::call(params: [
        'organization_id' => $customer->organization_id,
        'customer' => $customer,
        'name' => 'Empty wallet',
        'rate_amount' => '1',
        'currency' => 'EUR',
    ]);

    Queue::assertNotPushed(CreateJob::class);
});

it('performs the top-up: creates and settles the paid and granted transactions', function (): void {
    [, $customer] = walletSetup();
    $wallet = Wallet::factory()->forCustomer($customer)->create([
        'status' => WalletStatus::Active->value,
        'rate_amount' => '1',
        'traceable' => true,
    ]);

    $job = new CreateJob(
        organizationId: (string) $customer->organization_id,
        params: [
            'wallet_id' => $wallet->id,
            'paid_credits' => '10',
            'granted_credits' => '5',
            'source' => 'manual',
            'name' => 'Initial grant',
        ],
    );

    $job->handle();

    // The queue only records the settlement under the test connection —
    // run the worker side explicitly like production would.
    $paid = App\Models\WalletTransaction::query()
        ->where('wallet_id', $wallet->id)
        ->where('transaction_status', App\Enums\WalletTransactionCreditStatus::Purchased->value)
        ->first();

    (new App\Jobs\BillPaidCreditJob($paid, now()->getTimestamp()))->handle();

    $wallet->refresh();

    // Both transactions settled: the paid one billed onto its credit
    // invoice (BillPaidCreditJob runs inline on the sync queue), the
    // granted one increasing the balance directly.
    $transactions = $wallet->walletTransactions()->get();

    expect(count($transactions))->toBe(2);

    $paid = $transactions->firstWhere('transaction_status', App\Enums\WalletTransactionCreditStatus::Purchased->value);
    $granted = $transactions->firstWhere('transaction_status', App\Enums\WalletTransactionCreditStatus::Granted->value);

    expect($paid)->not->toBeNull()
        ->and($paid->fresh()->invoice_id)->not->toBeNull()
        ->and($paid->invoice->total_amount_cents)->toBe(1000)
        ->and($granted)->not->toBeNull()
        // Only the granted credits move the balance — the paid credits
        // settle when their invoice gets paid (pending status).
        ->and((int) $wallet->balance_cents)->toBe(500);
});
