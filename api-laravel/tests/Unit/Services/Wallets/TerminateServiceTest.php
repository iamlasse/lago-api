<?php

declare(strict_types=1);

require_once __DIR__.'/WalletsTestHelpers.php';

use App\Jobs\SendWebhookJob;
use App\Support\CurrentContext;
use Illuminate\Support\Facades\Queue;
use App\Services\Failures\NotFoundFailure;
use App\Services\Wallets\TerminateService;

beforeEach(function (): void {
    CurrentContext::reset();
});

it('terminates a wallet, flags the customer and emits the webhook', function (): void {
    Queue::fake();

    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer);
    // A still-active wallet keeps flag_wallets_for_refresh from being a
    // no-op (Rails skips the flag when no active wallet remains).
    walletFor($customer);

    $result = TerminateService::call(wallet: $wallet);

    expect($result->success())->toBeTrue();

    $wallet->refresh();

    expect($wallet->isTerminated())->toBeTrue()
        ->and($wallet->terminated_at)->not->toBeNull()
        ->and($customer->refresh()->awaiting_wallet_refresh)->toBeTrue();

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job) => $job->webhookType === 'wallet.terminated');
})->group('ledger:svc:Wallets.TerminateService');

it('is a no-op for an already terminated wallet and for a missing one', function (): void {
    Queue::fake();

    [$organization, $customer] = walletSetup();
    $wallet = terminatedWalletFor($customer);

    $result = TerminateService::call(wallet: $wallet);

    expect($result->success())->toBeTrue();
    Queue::assertNotPushed(SendWebhookJob::class);

    $missing = TerminateService::call(wallet: null);
    expect($missing->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($missing->getError()->resource)->toBe('wallet');
})->group('ledger:svc:Wallets.TerminateService');
