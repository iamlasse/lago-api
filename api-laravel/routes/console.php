<?php

declare(strict_types=1);

use App\Jobs\Clock\FinalizeInvoicesJob;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\Clock\SubscriptionsBillerJob;
use App\Jobs\Clock\RefreshDraftInvoicesJob;
use App\Jobs\Clock\ProcessDunningCampaignsJob;
use App\Jobs\Clock\MarkInvoicesAsPaymentOverdueJob;
use App\Jobs\Clock\RetryGeneratingSubscriptionInvoicesJob;

/*
|--------------------------------------------------------------------------
| Clock schedule (port of Rails' clock.rb)
|--------------------------------------------------------------------------
|
| The jobs carry their own unique-until-executed locks (UniqueJob
| middleware), mirroring SidekiqUniqueJobs — the scheduler only arms the
| cadence. Minutes match Rails' clock.rb crons; the offsets (:10, :20, ...)
| keep the hourly fan-outs from firing simultaneously.
*/

// every(5.minutes, "schedule:refresh_draft_invoices") — "*/5 * * * *"
Schedule::job(new RefreshDraftInvoicesJob)->everyFiveMinutes();

// every(1.hour, "schedule:bill_customers", at: "*:10") — "10 */1 * * *"
Schedule::job(new SubscriptionsBillerJob)->hourlyAt(10);

// every(1.hour, "schedule:finalize_invoices", at: "*:20") — "20 */1 * * *"
Schedule::job(new FinalizeInvoicesJob)->hourlyAt(20);

// every(1.hour, "schedule:mark_invoices_as_payment_overdue", at: "*:25") — "25 */1 * * *"
Schedule::job(new MarkInvoicesAsPaymentOverdueJob)->hourlyAt(25);

// every(1.hour, "schedule:retry_generating_subscription_invoices", at: "*:30") — "30 */1 * * *"
Schedule::job(new RetryGeneratingSubscriptionInvoicesJob)->hourlyAt(30);

// every(1.hour, "schedule:process_dunning_campaigns", at: "*:45") — "45 */1 * * *"
Schedule::job(new ProcessDunningCampaignsJob)->hourlyAt(45);

// Usage-monitoring slice (clock.rb entries):
// every(LAGO_SUBSCRIPTION_ACTIVITY_PROCESSING_INTERVAL_SECONDS || 1.minute,
//   "schedule:process_subscription_activity")
Schedule::job(new App\Jobs\Clock\ProcessAllSubscriptionActivitiesJob)->everyMinute();

// every(LAGO_LIFETIME_USAGE_REFRESH_INTERVAL_SECONDS || 5.minutes,
//   "schedule:refresh_lifetime_usages")
Schedule::job(new App\Jobs\Clock\RefreshLifetimeUsagesJob)->everyFiveMinutes();

// every(1.hour, "schedule:expire_order_forms", at: "*:40") — "40 */1 * * *"
Schedule::job(new App\Jobs\Clock\ExpireOrderFormsJob)->hourlyAt(40);

// Activation-rules slice (clock.rb entries):
// every(1.hour, "schedule:expire_incomplete_subscriptions", at: "*:20") — "20 */1 * * *"
Schedule::job(new App\Jobs\Clock\ExpireIncompleteSubscriptionsJob)->hourlyAt(20);

// every(1.hour, "schedule:bill_ended_trial_subscriptions", at: "*:35") — "35 */1 * * *"
Schedule::job(new App\Jobs\Clock\FreeTrialSubscriptionsBillerJob)->hourlyAt(35);

// ---------------------------------------------------------------------------
// Remaining clock.rb sweep (misc slice)
// ---------------------------------------------------------------------------

// every(5.minutes, "schedule:activate_subscriptions") — "*/5 * * * *"
Schedule::job(new App\Jobs\Clock\ActivateSubscriptionsJob)->everyFiveMinutes();

// Rails gates the wallet refresh on LAGO_MEMCACHE_SERVERS/LAGO_REDIS_CACHE_URL
// being present and LAGO_DISABLE_WALLET_REFRESH != "true"; the interval is
// LAGO_WALLET_ONGOING_BALANCE_REFRESH_INTERVAL_SECONDS (default 5.minutes).
if (filled(env('LAGO_REDIS_CACHE_URL')) && env('LAGO_DISABLE_WALLET_REFRESH') !== 'true') {
    $walletRefreshInterval = (int) env('LAGO_WALLET_ONGOING_BALANCE_REFRESH_INTERVAL_SECONDS', 300);

    Schedule::job(new App\Jobs\Clock\RefreshWalletsOngoingBalanceJob)
        ->cron('*/'.max(1, $walletRefreshInterval / 60).' * * * *');
}

// every(1.hour, "schedule:terminate_ended_subscriptions", at: "*:05") — "5 */1 * * *"
Schedule::job(new App\Jobs\Clock\TerminateEndedSubscriptionsJob)->hourlyAt(5);

// every(1.hour, "schedule:api_keys_track_usage", at: "*:15") — "15 */1 * * *"
Schedule::job(new App\Jobs\Clock\ApiKeys\TrackUsageJob)->hourlyAt(15);

// every(1.hour, "schedule:terminate_coupons", at: "*:30") — "30 */1 * * *"
Schedule::job(new App\Jobs\Clock\TerminateCouponsJob)->hourlyAt(30);

// every(1.hour, "schedule:terminate_wallets", at: "*:45") — "45 */1 * * *"
Schedule::job(new App\Jobs\Clock\TerminateWalletsJob)->hourlyAt(45);

// every(1.hour, "schedule:termination_alert", at: "*:50") — "50 */1 * * *"
Schedule::job(new App\Jobs\Clock\SubscriptionsToBeTerminatedJob)->hourlyAt(50);

// every(1.hour, "schedule:execute_scheduled_orders", at: "*:45") — "45 */1 * * *"
Schedule::job(new App\Jobs\Clock\ExecuteScheduledOrdersJob)->hourlyAt(45);

// every(1.day, "schedule:clean_webhooks", at: "01:00") — "0 1 * * *"
Schedule::job(new App\Jobs\Clock\WebhooksCleanupJob)->dailyAt('01:00');

// every(1.day, "schedule:clean_inbound_webhooks", at: "01:10") — cron "5 1 * * *"
// (Rails' clock.rb declares at: "01:10" but ships the "5 1 * * *" cron; the
// cron wins, exactly like Rails.)
Schedule::job(new App\Jobs\Clock\InboundWebhooksCleanupJob)->cron('5 1 * * *');
