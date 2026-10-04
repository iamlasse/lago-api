<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Fee;
use App\Models\Wallet;
use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Invoices\CustomerUsageService;
use App\Services\Wallets\Balance\RefreshOngoingUsageService;
use App\Services\Wallets\Balance\AllocateOngoingUsageByWalletsService;

/**
 * Port of Rails' Customers::RefreshWalletsService
 * (app/services/customers/refresh_wallets_service.rb) — recomputes every
 * active subscription's current usage and redistributes the ongoing usage
 * across the customer's wallets.
 *
 * The cascade makes every wallet's allocation depend on the others'
 * balances, so all wallets are persisted together.
 *
 * TODO(port): progressive billing fees (Subscriptions::ProgressiveBilledAmount
 * — the progressive-billing slice) are passed as an empty list; streaming
 * destinations (EventDestinations::CustomerUsage) are not ported.
 */
class RefreshWalletsService extends BaseService
{
    /** @var array<string, mixed>|null memoized subscription usage entries */
    private ?array $subscriptionUsages = null;

    public function __construct(
        private readonly Customer $customer,
        private readonly bool $includeGeneratingInvoices = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('wallets');

        $wallets = $this->allWallets();

        $allocationResult = AllocateOngoingUsageByWalletsService::call(
            customer: $this->customer,
            wallets: $wallets,
            currentUsageFees: $this->currentUsageFees(),
            draftInvoicesFees: $this->draftInvoicesFees(),
            progressiveBillingFees: [],
            payInAdvanceFees: $this->payInAdvanceFees(),
        );

        $allocationResult->raiseIfError();

        /** @var array<string, int> $walletAllocations */
        $walletAllocations = $allocationResult->wallet_allocations;

        foreach ($wallets as $wallet) {
            RefreshOngoingUsageService::callBang(
                wallet: $wallet,
                ongoingUsageAmountCents: $walletAllocations[$wallet->id] ?? 0,
                skipSingleWalletUpdate: true,
            );
        }

        Wallet::query()->whereIn('id', collect($wallets)->pluck('id'))
            ->update(['last_ongoing_balance_sync_at' => now()]);

        $this->customer->awaiting_wallet_refresh = false;
        $this->customer->save();

        // TODO(port): deliver_streaming_events (StreamingDestinations slice).

        $result->wallets = $this->customer->wallets()->active()->get();

        return $result;
    }

    /** @return list<Wallet> */
    private function allWallets(): array
    {
        return Wallet::inApplicationOrder()
            ->where('customer_id', $this->customer->id)
            ->active()
            ->get()
            ->all();
    }

    /** @return list<Fee> */
    private function currentUsageFees(): array
    {
        $fees = [];

        foreach ($this->subscriptionUsages() as $entry) {
            foreach ($entry['usage']->fees as $fee) {
                $fees[] = $fee;
            }
        }

        return $fees;
    }

    /**
     * Must be a subset of current_usage_fees so both buckets share fee keys
     * and net out.
     *
     * @return list<Fee>
     */
    private function payInAdvanceFees(): array
    {
        return array_values(array_filter(
            $this->currentUsageFees(),
            fn (Fee $fee) => $fee->charge !== null && $fee->charge->payInAdvance(),
        ));
    }

    /** @return list<Fee> */
    private function draftInvoicesFees(): array
    {
        $fees = [];

        $invoices = $this->customer->invoices()
            ->where('status', \App\Enums\InvoiceStatus::Draft->value)
            ->whereNot('total_amount_cents', 0)
            ->with('fees.charge')
            ->get();

        foreach ($invoices as $invoice) {
            foreach ($invoice->fees as $fee) {
                $fees[] = $fee;
            }
        }

        return $fees;
    }

    /**
     * One entry per active subscription: its current-usage computation.
     * Rails also carries the progressively billed invoice subscriptions
     * (TODO(port), progressive-billing slice).
     *
     * @return list<array{subscription: \App\Models\Subscription, invoice: \App\Models\Invoice, usage: \App\Support\SubscriptionUsage}>
     */
    private function subscriptionUsages(): array
    {
        if ($this->subscriptionUsages !== null) {
            return $this->subscriptionUsages;
        }

        $entries = [];

        $subscriptions = $this->customer->subscriptions()->active()->get();

        foreach ($subscriptions as $subscription) {
            $usageResult = CustomerUsageService::call(
                customer: $this->customer,
                subscription: $subscription,
            );

            $usageResult->raiseIfError();

            $entries[] = [
                'subscription' => $subscription,
                'invoice' => $usageResult->invoice,
                'usage' => $usageResult->usage,
            ];
        }

        return $this->subscriptionUsages = $entries;
    }
}
