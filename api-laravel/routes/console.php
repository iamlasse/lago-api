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
