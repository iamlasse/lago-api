<?php

declare(strict_types=1);

namespace App\Services\LifetimeUsages;

use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Models\LifetimeUsage;
use App\Services\Failures\UnknownTaxFailure;
use App\Services\Invoices\ProgressiveBillingService;
use App\Services\Subscriptions\ProgressiveBilledAmount;

/**
 * Port of Rails' LifetimeUsages::CheckThresholdsService
 * (app/services/lifetime_usages/check_thresholds_service.rb) — on a passed
 * threshold: generate the progressive-billing invoice, then send one
 * subscription.usage_threshold_reached webhook per passed threshold (after
 * the invoice exists, because the job might have been scheduled multiple
 * times).
 */
class CheckThresholdsService extends \App\Services\BaseService
{
    public function __construct(private readonly LifetimeUsage $lifetimeUsage)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoice');
        $lifetimeUsage = $this->lifetimeUsage;
        $subscription = $lifetimeUsage->subscription;

        if (! $subscription->active()) {
            return $result;
        }

        $progressiveBilledAmount = ProgressiveBilledAmount::callBang(subscription: $subscription)->progressive_billed_amount;

        $checkResult = UsageThresholds\CheckService::callBang(
            lifetimeUsage: $lifetimeUsage,
            progressiveBilledAmount: $progressiveBilledAmount,
        );

        $passedThresholds = $checkResult->passed_thresholds;

        if ($passedThresholds === []) {
            return $result;
        }

        $invoiceResult = ProgressiveBillingService::call(
            sortedUsageThresholds: $passedThresholds,
            lifetimeUsage: $lifetimeUsage,
        );

        // If there is a tax error, the invoice is marked as failed and it can be
        // retried manually.
        if (! ($invoiceResult->success()
            || $invoiceResult->getError() instanceof UnknownTaxFailure)) {
            $invoiceResult->raiseIfError();
        }

        $result->invoice = $invoiceResult->invoice;

        foreach ($passedThresholds as $usageThreshold) {
            SendWebhookJob::performLater(
                'subscription.usage_threshold_reached',
                $subscription,
                ['usage_threshold' => $usageThreshold],
            );
        }

        return $result;
    }
}
