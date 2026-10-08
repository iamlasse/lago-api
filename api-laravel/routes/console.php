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

// Billing-segments slice (clock.rb entries):
// every(1.hour, "schedule:create_billing_segments", at: "*:12") — "12 */1 * * *"
Schedule::job(new App\Jobs\Clock\CreateBillingSegmentsJob)->hourlyAt(12);

// Five minutes behind the producer, so a card that comes due is invoiced in
// the same hour. A fan-out that runs long only defers its stragglers to the
// next tick; nothing is lost.
// every(1.hour, "schedule:process_billing_segments", at: "*:17") — "17 */1 * * *"
Schedule::job(new App\Jobs\Clock\ProcessBillingSegmentsJob)->hourlyAt(17);

// every(1.hour, "schedule:terminate_coupons", at: "*:30") — "30 */1 * * *"
Schedule::job(new App\Jobs\Clock\TerminateCouponsJob)->hourlyAt(30);

// every(1.hour, "schedule:terminate_wallets", at: "*:45") — "45 */1 * * *"
Schedule::job(new App\Jobs\Clock\TerminateWalletsJob)->hourlyAt(45);

// every(1.hour, "schedule:termination_alert", at: "*:50") — "50 */1 * * *"
Schedule::job(new App\Jobs\Clock\SubscriptionsToBeTerminatedJob)->hourlyAt(50);

// every(1.hour, "schedule:terminate_expired_wallet_transaction_rules", at: "*:50") — "50 */1 * * *"
Schedule::job(new App\Jobs\Clock\TerminateRecurringTransactionRulesJob)->hourlyAt(50);

// every(1.hour, "schedule:top_up_wallet_interval_credits", at: "*:55") — "55 */1 * * *"
Schedule::job(new App\Jobs\Clock\CreateIntervalWalletTransactionsJob)->hourlyAt(55);

// every(1.hour, "schedule:execute_scheduled_orders", at: "*:45") — "45 */1 * * *"
Schedule::job(new App\Jobs\Clock\ExecuteScheduledOrdersJob)->hourlyAt(45);

// every(1.day, "schedule:clean_webhooks", at: "01:00") — "0 1 * * *"
Schedule::job(new App\Jobs\Clock\WebhooksCleanupJob)->dailyAt('01:00');

// every(1.day, "schedule:clean_inbound_webhooks", at: "01:10") — cron "5 1 * * *"
// (Rails' clock.rb declares at: "01:10" but ships the "5 1 * * *" cron; the
// cron wins, exactly like Rails.)
Schedule::job(new App\Jobs\Clock\InboundWebhooksCleanupJob)->cron('5 1 * * *');

// ---------------------------------------------------------------------------
// Retry/recovery slice (clock.rb entries)
// ---------------------------------------------------------------------------

// every(1.hour, "schedule:cancel_abandoned_payments", at: "*:40") — "40 */1 * * *"
Schedule::job(new App\Jobs\Clock\CancelAbandonedPaymentsJob)->hourlyAt(40);

// every(15.minutes, "schedule:retry_failed_invoices") — "*/15 * * * *"
Schedule::job(new App\Jobs\Clock\RetryFailedInvoicesJob)->everyFifteenMinutes();

// every(15.minutes, "schedule:retry_inbound_webhooks") — "*/15 * * * *"
Schedule::job(new App\Jobs\Clock\InboundWebhooksRetryJob)->everyFifteenMinutes();

// ---------------------------------------------------------------------------
// Usage/records completion slice (clock.rb entries)
// ---------------------------------------------------------------------------

// every(1.hour, "schedule:post_validate_events", at: "*:05") — "5 */1 * * *"
// Rails skips the entry entirely when LAGO_DISABLE_EVENTS_VALIDATION is set.
if (env('LAGO_DISABLE_EVENTS_VALIDATION') !== true && filter_var(env('LAGO_DISABLE_EVENTS_VALIDATION'), FILTER_VALIDATE_BOOL) !== true) {
    Schedule::job(new App\Jobs\Clock\EventsValidationJob)->hourlyAt(5);
}

// every(1.hour, "schedule:compute_daily_usage", at: "*:15") — "15 */1 * * *"
Schedule::job(new App\Jobs\Clock\ComputeAllDailyUsagesJob)->hourlyAt(15);

// every(1.day, "schedule:clean_record_deletions", at: "01:20") — cron "20 1 * * *"
Schedule::job(new App\Jobs\Clock\RecordDeletionsCleanupJob)->cron('20 1 * * *');
