<?php

declare(strict_types=1);

namespace App\Services\Wallets\Balance;

use Closure;
use App\Models\Fee;
use App\Models\Wallet;
use App\Models\Customer;
use App\Support\License;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Collection;

/**
 * Port of Rails' Wallets::Balance::AllocateOngoingUsageByWalletsService
 * (app/services/wallets/balance/allocate_ongoing_usage_by_wallets_service.rb)
 * — distributes ongoing (unbilled) usage across the customer's wallets in
 * priority order. A wallet with an active threshold-based recurring rule is
 * the exception: it absorbs everything and may go negative (no cascade) so
 * the rule can fire and refill it.
 *
 * TODO(port): threshold_wallet? needs RecurringTransactionRule (no model
 * yet) — treated as if no wallet carries a threshold rule.
 */
class AllocateOngoingUsageByWalletsService extends BaseService
{
    public function __construct(
        private readonly Customer $customer,
        /** @var iterable<Wallet> */
        private readonly iterable $wallets,
        /** @var iterable<Fee> */
        private readonly iterable $currentUsageFees,
        /** @var iterable<Fee> */
        private readonly iterable $draftInvoicesFees,
        /** @var iterable<Fee> */
        private readonly iterable $progressiveBillingFees,
        /** @var iterable<Fee> */
        private readonly iterable $payInAdvanceFees,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('wallet_allocations');

        $result->wallet_allocations = $this->calculateWalletAllocations();

        return $result;
    }

    /** @return array<string, int> wallet id => allocated cents */
    private function calculateWalletAllocations(): array
    {
        $wallets = $this->wallets instanceof Collection ? $this->wallets : collect($this->wallets);

        $netAmounts = $this->netUsageByFeeKey();
        $budgets = $this->currencyBudgets($netAmounts);
        $balances = $this->freshBalances($wallets);
        $metas = $wallets->map(fn (Wallet $wallet) => $this->walletMeta($wallet, $balances));
        $allocations = [];
        foreach ($wallets as $wallet) {
            $allocations[$wallet->id] = 0;
        }

        foreach ($this->allocatablePool($netAmounts) as $feeKey => $keyAmount) {
            $currency = $this->decodeFeeKey($feeKey)[3];
            $remaining = min($keyAmount, $budgets[$currency] ?? 0);

            $applicable = $metas->filter(
                fn (array $meta) => $this->applicableFee(feeKey: $feeKey, wallet: $meta['wallet'], targets: $meta['targets'], types: $meta['types']),
            )->values();

            foreach ($applicable as $index => $meta) {
                if ($remaining <= 0) {
                    break;
                }

                if ($meta['threshold'] || $index === $applicable->count() - 1) {
                    // A threshold wallet absorbs everything so its rule can refill it;
                    // the last applicable wallet absorbs the overflow. Both are allowed
                    // to go negative.
                    $take = $remaining;
                } else {
                    $room = $meta['balance'] - $allocations[$meta['wallet']->id];
                    if ($room <= 0) {
                        continue;
                    }
                    $take = min($remaining, $room);
                }

                $allocations[$meta['wallet']->id] += $take;
                $remaining -= $take;
                $budgets[$currency] -= $take;
            }
        }

        return $allocations;
    }

    /**
     * @param  array<string, int>  $balances
     * @return array{wallet: Wallet, targets: list<array{0: string, 1: string}>, types: list<string>, threshold: bool, balance: int}
     */
    private function walletMeta(Wallet $wallet, array $balances): array
    {
        $threshold = $this->thresholdWallet($wallet);

        $targets = [];
        foreach ($wallet->walletTargets as $walletTarget) {
            if ($walletTarget->billable_metric_id !== null) {
                $targets[] = ['charge', $walletTarget->billable_metric_id];
            }
        }

        return [
            'wallet' => $wallet,
            'targets' => $targets,
            'types' => (array) ($wallet->allowed_fee_types ?? []),
            'threshold' => $threshold,
            'balance' => $threshold ? 0 : ($balances[$wallet->id] ?? 0),
        ];
    }

    /**
     * Re-read balances so a concurrent pay-in-advance DecreaseService can't
     * make us cap against stale in-memory values: one query for all wallets,
     * one consistent snapshot.
     *
     * @param  Collection<Wallet>  $wallets
     * @return array<string, int>
     */
    private function freshBalances(Collection $wallets): array
    {
        return Wallet::query()
            ->whereIn('id', $wallets->pluck('id'))
            ->pluck('balance_cents', 'id')
            ->all();
    }

    /**
     * Net the fee buckets into a signed amount per fee key. Keys whose net
     * is <= 0 (already fully billed) cannot receive allocations, but their
     * negative nets still reduce the per-currency budget the same way
     * billing credits reduce the invoice total.
     *
     * @return array<string, int>
     */
    private function netUsageByFeeKey(): array
    {
        $netAmounts = [];

        $this->addToPool($netAmounts, $this->currentUsageFees, fn (Fee $fee) => (int) $fee->amount_cents + (int) $fee->taxes_amount_cents);
        $this->addToPool($netAmounts, $this->draftInvoicesFees, fn (Fee $fee) => (int) $fee->amount_cents + (int) $fee->taxes_amount_cents - (int) (string) $fee->precise_coupons_amount_cents);
        $this->addToPool($netAmounts, $this->progressiveBillingFees, fn (Fee $fee) => -((int) (string) $fee->subTotalExcludingTaxesAmountCents() + (int) $fee->taxes_amount_cents));
        $this->addToPool($netAmounts, $this->payInAdvanceFees, fn (Fee $fee) => -((int) $fee->amount_cents + (int) $fee->taxes_amount_cents));

        return $netAmounts;
    }

    /**
     * @param  array<string, int>  $netAmounts
     * @return array<string, int>
     */
    private function allocatablePool(array $netAmounts): array
    {
        $positive = array_filter($netAmounts, fn (int $amount) => $amount > 0);

        // Ties broken by fee key so the allocation order is stable across
        // refreshes.
        uksort($positive, strcmp(...));
        uasort($positive, fn (int $x, int $y) => $y <=> $x);

        return $positive;
    }

    /**
     * Mirrors billing's remaining_invoice_amount: an over-billed key's
     * negative net offsets the other keys in its currency, the same way
     * credits offset the invoice at billing.
     *
     * @param  array<string, int>  $netAmounts
     * @return array<string, int>
     */
    private function currencyBudgets(array $netAmounts): array
    {
        $budgets = [];

        foreach ($netAmounts as $feeKey => $amount) {
            $currency = $this->decodeFeeKey($feeKey)[3];
            $budgets[$currency] = ($budgets[$currency] ?? 0) + $amount;
        }

        foreach ($budgets as $currency => $amount) {
            $budgets[$currency] = max($amount, 0);
        }

        return $budgets;
    }

    /** @param iterable<Fee> $fees */
    private function addToPool(array &$pool, iterable $fees, Closure $amountFor): void
    {
        foreach ($fees as $fee) {
            $key = $this->feeKey($fee);
            $pool[$key] = ($pool[$key] ?? 0) + $amountFor($fee);
        }
    }

    /** Rails: `[fee_type, charge.billable_metric_id, target_wallet_code, amount_currency]`, encoded to a string key. */
    private function feeKey(Fee $fee): string
    {
        $targetWalletCode = null;

        if ($this->feeTargetingWalletsEnabled() && $fee->charge?->accepts_target_wallet) {
            $targetWalletCode = $fee->grouped_by['target_wallet_code'] ?? null;
        }

        return $this->encodeFeeKey([
            (string) $fee->fee_type?->label(),
            $fee->charge?->billable_metric_id !== null ? (string) $fee->charge->billable_metric_id : '',
            $targetWalletCode !== null ? (string) $targetWalletCode : '',
            (string) $fee->amount_currency,
        ]);
    }

    private function encodeFeeKey(array $parts): string
    {
        return implode('|', $parts);
    }

    /** @return list<string> */
    private function decodeFeeKey(string $feeKey): array
    {
        return explode('|', $feeKey);
    }

    /** @param list<array{0: string, 1: string}> $targets @param list<string> $types */
    private function applicableFee(string $feeKey, Wallet $wallet, array $targets, array $types): bool
    {
        [$feeType, $billableMetricId, $targetWalletCode, $currency] = $this->decodeFeeKey($feeKey);

        if ($wallet->balance_currency !== $currency) {
            return false;
        }

        if ($targetWalletCode !== '') {
            return $wallet->code === $targetWalletCode;
        }

        $targetMatch = in_array([$feeType, $billableMetricId], $targets, true);
        $typeMatch = in_array($feeType, $types, true);
        $unrestrictedWallet = $targets === [] && $types === [];

        return $targetMatch || $typeMatch || $unrestrictedWallet;
    }

    /** Rails: `threshold_wallet?` — a currently-active threshold rule makes the wallet absorb everything. */
    private function thresholdWallet(Wallet $wallet): bool
    {
        return $wallet->recurringTransactionRules()
            ->get()
            ->contains(fn ($rule) => $rule->currentlyActive() && $rule->isThreshold());
    }

    private function feeTargetingWalletsEnabled(): bool
    {
        $integrations = $this->customer->organization?->premium_integrations ?? [];

        return License::premium()
            && in_array('events_targeting_wallets', (array) $integrations, true);
    }
}
