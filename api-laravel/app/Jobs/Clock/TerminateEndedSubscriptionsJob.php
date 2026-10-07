<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Queue\InteractsWithQueue;
use App\Jobs\Subscriptions\TerminateEndedSubscriptionJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' Clock::TerminateEndedSubscriptionsJob
 * (app/jobs/clock/terminate_ended_subscriptions_job.rb) — the hourly sweep
 * fanning out a TerminateEndedSubscriptionJob per active subscription whose
 * ending_at day (in the customer's timezone) has arrived.
 */
class TerminateEndedSubscriptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Rails: ENDING_AT_PREFILTER = 3.days. */
    public const int ENDING_AT_PREFILTER_DAYS = 3;

    public function __construct()
    {
        $this->onQueue('clock');
    }

    /** Port of `unique :until_executed, on_conflict: :log, lock_ttl: 4.hours`. */
    public function uniqueFor(): int
    {
        return 4 * 3600;
    }

    public function middleware(): array
    {
        return [new UniqueJob];
    }

    public function handle(): void
    {
        $now = now();
        $atTimeZone = self::atTimeZone();

        // Optimization (ported verbatim from Rails): the DATE comparison reads
        // the timezone from joined rows, so no index can help it; the
        // ending_at range is plain UTC, indexed, and runs first. Every row the
        // DATE comparison matches fits in it, with room to spare for any
        // timezone.
        $subscriptions = Subscription::query()
            ->join('customers', 'subscriptions.customer_id', '=', 'customers.id')
            ->join('billing_entities', 'customers.billing_entity_id', '=', 'billing_entities.id')
            ->active()
            ->whereBetween('subscriptions.ending_at', [
                $now->clone()->subDays(self::ENDING_AT_PREFILTER_DAYS),
                $now->clone()->addDays(self::ENDING_AT_PREFILTER_DAYS),
            ])
            ->whereRaw(
                "DATE(subscriptions.ending_at{$atTimeZone}) = DATE(?{$atTimeZone})",
                [$now->toDateTimeString()],
            )
            ->get();

        /** @var Subscription $subscription */
        foreach ($subscriptions as $subscription) {
            TerminateEndedSubscriptionJob::dispatch($subscription);
        }
    }

    /** Rails: Utils::Timezone.at_time_zone_sql (see FreeTrialBillingService). */
    protected static function atTimeZone(string $customer = 'customers', string $billingEntity = 'billing_entities'): string
    {
        return "::timestamptz AT TIME ZONE COALESCE({$customer}.timezone, {$billingEntity}.timezone, 'UTC')";
    }
}
