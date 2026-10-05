<?php

declare(strict_types=1);

namespace App\Services\QuoteVersions\Validators;

use App\Models\Plan;

/**
 * Port of Rails' QuoteVersions::Validators::SubscriptionAmendment::BusinessValidator
 * (app/services/quote_versions/validators/subscription_amendment/business_validator.rb).
 *
 * An amendment carries a subscription_creation payload, so its schema is
 * reused as is and currency, plan overrides, coupons and wallet credits are
 * all inherited. Only what is specific to restating one plan on a live
 * subscription is added here, and the inherited plan date validation is
 * narrowed down to the ending date, the one an amendment carries over.
 */
class SubscriptionAmendmentValidator extends SubscriptionCreationValidator
{
    public function businessValid(): bool
    {
        $this->validateSinglePlan();
        $this->validateTargetSubscription();

        // Errors accumulate in one hash, so the inherited pass still reports
        // everything at once.
        return parent::businessValid();
    }

    /**
     * Refused at both scopes so a draft never accumulates a second plan: the
     * quote pins a single target subscription, and execution amends that one.
     */
    protected function validateSinglePlan(): void
    {
        if (count($this->plans()) <= 1) {
            return;
        }

        $this->addError('billing_items.plans', 'single_plan_expected');
    }

    /**
     * The target subscription is already bound to an entity, and the plan
     * change carries that binding over. Re-pinning it here would move a
     * running subscription to another entity's invoice-numbering series
     * mid-life, so an amendment cannot name one at all.
     */
    protected function validateBillingEntity(): void
    {
        if (($this->quoteVersion->billing_entity_id ?? '') === '') {
            return;
        }

        $this->addError('billing_entity_id', 'not_supported_for_order_type');
    }

    /**
     * An amendment restates the term of a subscription that is already
     * running: its start is the target's own anniversary date, which the plan
     * change carries over, so a quoted start date is a commercial term only
     * and is not validated here. The ending date only has to be after the
     * target's anniversary date, which its futureness already implies.
     */
    protected function validatePlanDates(array $planItem, int $index): void
    {
        $endDate = $planItem['payload']['endDate'] ?? null;

        if (! $this->validatePlanDate($endDate, $this->planField($index, 'payload.endDate'))) {
            return;
        }

        $this->validateFutureEndDate($endDate, $this->planField($index, 'payload.endDate'));
    }

    /**
     * The quote pins its target at creation, where Quote requires it for this
     * order type and Quotes::CreateService scopes it to the deal's
     * organization and customer. Only the state that can change afterwards is
     * checked here.
     */
    protected function validateTargetSubscription(): void
    {
        $subscription = $this->quoteVersion->quote->subscription;

        if ($subscription === null) {
            $this->addError('subscription_id', 'value_is_mandatory');

            return;
        }

        if ($subscription->status !== 'active') {
            $this->addError('subscription_id', 'subscription_not_active');
        }
    }
}
