<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use App\Models\Subscription;
use App\Models\Webhook;
use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use App\Jobs\SendWebhookJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' Clock::SubscriptionsToBeTerminatedJob
 * (app/jobs/clock/subscriptions_to_be_terminated_job.rb) — the hourly
 * termination alert: one `subscription.termination_alert` webhook per active
 * subscription whose ending_at day matches one of the configured
 * sent-at offsets (15 and 45 days by default), de-duplicated per day via
 * the webhooks table.
 */
class SubscriptionsToBeTerminatedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

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
        $today = $now->toDateString();
        $sentAtDates = $this->sentAtDates($now);

        Subscription::query()
            ->active()
            ->whereRaw('DATE(ending_at::timestamptz) IN ('.implode(', ', array_fill(0, count($sentAtDates)), '?').')', $sentAtDates)
            ->chunkById(1000, function ($subscriptions) use ($today): void {
                $subscriptionIdsAlreadyAlerted = Webhook::query()
                    ->where('webhook_type', 'subscription.termination_alert')
                    ->where('object_type', 'Subscription')
                    ->whereIn('object_id', $subscriptions->modelKeys())
                    ->whereRaw('created_at::date = ?', [$today])
                    ->pluck('object_id')
                    ->all();

                /** @var Subscription $subscription */
                foreach ($subscriptions as $subscription) {
                    if (in_array($subscription->id, $subscriptionIdsAlreadyAlerted, true)) {
                        continue;
                    }

                    SendWebhookJob::performLater('subscription.termination_alert', $subscription);
                }
            });
    }

    /**
     * Rails: `sent_at_dates` — the alert is sent 15 and 45 days before the
     * subscription is terminated by default; override with
     * LAGO_SUBSCRIPTION_TERMINATION_ALERT_SENT_AT_DAYS (e.g. "1,15,45").
     *
     * @return list<string> Y-m-d dates
     */
    protected function sentAtDates(mixed $now): array
    {
        $sentAtDaysConfig = env('LAGO_SUBSCRIPTION_TERMINATION_ALERT_SENT_AT_DAYS', '15,45');

        return array_map(
            fn (string $dayString): string => $now->clone()->addDays((int) $dayString)->toDateString(),
            array_map('trim', explode(',', (string) $sentAtDaysConfig)),
        );
    }
}
