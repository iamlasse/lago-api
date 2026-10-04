<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Invoice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Services\Invoices\SubscriptionService;

/**
 * Port of Rails' db/seeds/50_invoices.rb: back-fills one periodic invoice
 * per elapsed subscription month. Runs only while the invoices table is
 * empty (global check, as upstream).
 */
class InvoicesSeeder extends Seeder
{
    public function run(): void
    {
        // Invoices\SubscriptionService runs inside a transaction upstream.
        DB::transaction(function (): void {
            $this->seed();
        });
    }

    private function seed(): void
    {
        if (Invoice::count() !== 0) {
            return;
        }

        $subscriptions = \App\Models\Subscription::query()->get();

        foreach ($subscriptions as $subscription) {
            $elapsedSeconds = max(0, now()->getTimestamp() - $subscription->subscription_at->getTimestamp());
            // Rails divides by 1.month (Duration = 2_592_000 seconds).
            $invoiceCount = (int) round($elapsedSeconds / 2_592_000);

            for ($offset = 1; $offset <= $invoiceCount; $offset++) {
                // Rails uses the non-bang `.call` and swallows failures.
                SubscriptionService::call(
                    subscriptions: [$subscription],
                    timestamp: $subscription->subscription_at->clone()->addMonths($offset)->getTimestamp(),
                    invoicingReason: 'subscription_periodic',
                );
            }
        }
    }
}
