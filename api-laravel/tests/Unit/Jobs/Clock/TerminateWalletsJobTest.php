<?php

declare(strict_types=1);

use App\Models\Wallet;
use Database\Factories\WalletFactory;
use App\Jobs\Clock\TerminateWalletsJob;

uses()->group('ledger:job:Clock.TerminateWalletsJob');

/**
 * Port of Rails' spec/jobs/clock/terminate_wallets_job_spec.rb — the hourly
 * sweep terminates every active wallet past its expiration_at.
 */
function walletForClock(array $attributes = []): Wallet
{
    /** @var WalletFactory $factory */
    $factory = Wallet::factory();

    return $factory->state(fn (): array => $attributes)->create();
}

it('terminates the expired wallets', function (): void {
    $toExpire = walletForClock(['expiration_at' => now()->subDays(40)]);
    $toKeep = walletForClock(['expiration_at' => now()->addDays(40)]);

    (new TerminateWalletsJob)->handle();

    expect($toExpire->refresh()->statusEnum()?->label())->toBe('terminated')
        ->and($toKeep->refresh()->isActive())->toBeTrue();
});
