<?php

declare(strict_types=1);

namespace App\Services\RateCardRates;

use App\Models\RateCardRate;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Support\Utils\Datetime;
use App\Services\Failures\FailedResult;

use function array_key_exists;

/**
 * Port of Rails' RateCardRates::UpdateService
 * (app/services/rate_card_rates/update_service.rb).
 */
class UpdateService extends BaseService
{
    /**
     * Terminated rates are frozen, active rates only accept new pricing
     * values, pending rates are fully editable. Frozen fields are rejected
     * on presence: sending one on an active rate is an error even with an
     * unchanged value.
     */
    private const FROZEN_ON_ACTIVE = [
        'effective_from',
        'rate_model',
        'min_amount_cents',
        'billing_interval_count',
        'billing_interval_unit',
    ];

    public function __construct(
        private readonly ?RateCardRate $rateCardRate,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('rate_card_rate');
        $rateCardRate = $this->rateCardRate;
        $params = $this->params;

        try {
            if ($rateCardRate === null) {
                return $result->notFoundFailure('rate_card_rate');
            }

            // On a card billed by subscriptions only pending rates stay
            // editable: the active rate prices live subscriptions; changes
            // go through appends.
            if ($rateCardRate->rateCard->attachedToSubscriptions() && ! $rateCardRate->isPending()) {
                return $result->singleValidationFailure('attached_to_subscriptions', 'rate_card');
            }

            if ($rateCardRate->isTerminated()) {
                return $result->singleValidationFailure('terminated_rate_not_editable', 'status');
            }

            if ($rateCardRate->isActive()) {
                foreach (self::FROZEN_ON_ACTIVE as $frozenField) {
                    if (array_key_exists($frozenField, $params)) {
                        return $result->singleValidationFailure('not_editable_on_active_rate', $frozenField);
                    }
                }
            }

            // Code is identity: editable until the card is in a plan or
            // subscription.
            if (array_key_exists('code', $params)
                && mb_trim((string) ($params['code'] ?? '')) !== $rateCardRate->code
                && $rateCardRate->rateCard->attachedToPlanOrSubscription()) {
                return $result->singleValidationFailure('attached_to_plan_or_subscription', 'code');
            }

            if (array_key_exists('effective_from', $params)
                && Datetime::beforeToday($params['effective_from'], $rateCardRate->organization->timezone ?? 'UTC')) {
                return $result->singleValidationFailure('must_not_be_before_today', 'effective_from');
            }

            if (array_key_exists('rate_properties', $params)) {
                // TODO(port): RateProperties::NormalizeRangesService.
            }

            if (array_key_exists('code', $params)) {
                $rateCardRate->code = $params['code'] === null ? null : mb_trim((string) $params['code']);
            }
            if (array_key_exists('effective_from', $params)) {
                $rateCardRate->effective_from = Datetime::parseIso8601($params['effective_from']);
            }
            if (array_key_exists('rate_model', $params)) {
                $rateCardRate->rate_model = $params['rate_model'];
            }
            if (array_key_exists('rate_properties', $params)) {
                $rateCardRate->rate_properties = $params['rate_properties'];
            }
            if (array_key_exists('min_amount_cents', $params)) {
                $rateCardRate->min_amount_cents = $params['min_amount_cents'];
            }
            if (array_key_exists('billing_interval_count', $params)) {
                $rateCardRate->billing_interval_count = $params['billing_interval_count'];
            }
            if (array_key_exists('billing_interval_unit', $params)) {
                $rateCardRate->billing_interval_unit = $params['billing_interval_unit'];
            }
            if (array_key_exists('applied_pricing_unit_conversion_rate', $params)) {
                $rateCardRate->applied_pricing_unit_conversion_rate = $params['applied_pricing_unit_conversion_rate'];
            }

            // TODO(port): activity_loggable (rate_card.updated).
            $errors = $rateCardRate->validateAttributes();
            if ($errors !== []) {
                $result->recordValidationFailure($errors)->raiseIfError();
            }

            $rateCardRate->save();

            $result->rate_card_rate = $rateCardRate;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
