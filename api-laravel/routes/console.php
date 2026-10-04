<?php

declare(strict_types=1);

use App\Jobs\Clock\FinalizeInvoicesJob;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\Clock\SubscriptionsBillerJob;
use App\Jobs\Clock\RefreshDraftInvoicesJob;
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
