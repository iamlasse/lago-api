<?php

declare(strict_types=1);

namespace App\Services\Wallets\Balance;

use App\Models\Wallet;
use App\Support\MoneyMath;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' Wallets::Balance::RefreshOngoingUsageService
 * (app/services/wallets/balance/refresh_ongoing_usage_service.rb).
 *
 * If a pay_in_advance fee landed while the usage was being computed, the
 * wallet version in memory is stale — the wallet is reloaded before the
 * update (Rails' StaleObjectError guard).
 */
class RefreshOngoingUsageService extends BaseService
{
    public function __construct(
        private readonly Wallet $wallet,
        private readonly int $ongoingUsageAmountCents,
        private readonly bool $skipSingleWalletUpdate = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('wallet');
        $wallet = $this->wallet->fresh() ?? $this->wallet;

        $updateParams = $this->walletUpdateParams($wallet);

        UpdateOngoingService::call(
            wallet: $wallet,
            updateParams: $updateParams,
            skipSingleWalletUpdate: $this->skipSingleWalletUpdate,
        )->raiseIfError();

        $result->wallet = $wallet;

        return $result;
    }

    /** @return array<string, int|string|bool> */
    private function walletUpdateParams(Wallet $wallet): array
    {
        $params = [
            'ongoing_usage_balance_cents' => $this->ongoingUsageBalanceCents(),
            'credits_ongoing_usage_balance' => $this->creditsOngoingUsageBalance($wallet),
            'ongoing_balance_cents' => $this->ongoingBalanceCents($wallet),
            'credits_ongoing_balance' => $this->creditsOngoingBalance($wallet),
        ];

        if (! (bool) $wallet->depleted_ongoing_balance && $this->ongoingBalanceCents($wallet) <= 0) {
            $params['depleted_ongoing_balance'] = true;
        } elseif ((bool) $wallet->depleted_ongoing_balance && $this->ongoingBalanceCents($wallet) > 0) {
            $params['depleted_ongoing_balance'] = false;
        }

        return $params;
    }

    private function currency(Wallet $wallet): object
    {
        return $wallet->currencyForBalance();
    }

    private function ongoingUsageBalanceCents(): int
    {
        return $this->ongoingUsageAmountCents;
    }

    private function creditsOngoingUsageBalance(Wallet $wallet): string
    {
        return MoneyMath::fdiv(
            MoneyMath::fdiv((string) $this->ongoingUsageBalanceCents(), (string) $this->currency($wallet)->subunit_to_unit),
            (string) $wallet->rate_amount,
        );
    }

    private function ongoingBalanceCents(Wallet $wallet): int
    {
        return (int) $wallet->balance_cents - $this->ongoingUsageBalanceCents();
    }

    private function creditsOngoingBalance(Wallet $wallet): string
    {
        return MoneyMath::fdiv(
            MoneyMath::fdiv((string) $this->ongoingBalanceCents($wallet), (string) $this->currency($wallet)->subunit_to_unit),
            (string) $wallet->rate_amount,
        );
    }
}
