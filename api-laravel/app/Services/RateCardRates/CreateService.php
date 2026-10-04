<?php

declare(strict_types=1);

namespace App\Services\RateCardRates;

use App\Models\RateCard;
use App\Models\RateCardRate;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Support\Utils\Datetime;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' RateCardRates::CreateService
 * (app/services/rate_card_rates/create_service.rb).
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly ?RateCard $rateCard,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('rate_card_rate');
        $rateCard = $this->rateCard;
        $params = $this->params;

        try {
            if ($rateCard === null) {
                return $result->notFoundFailure('rate_card');
            }

            if (Datetime::beforeToday($params['effective_from'] ?? null, $rateCard->organization->timezone ?? 'UTC')) {
                return $result->singleValidationFailure('must_not_be_before_today', 'effective_from');
            }

            $effectiveFrom = Datetime::parseIso8601($params['effective_from'] ?? null);

            $rate = new RateCardRate([
                'organization_id' => $rateCard->organization_id,
                'rate_card_id' => $rateCard->id,
                'code' => isset($params['code']) && $params['code'] !== '' ? $params['code'] : null,
                'effective_from' => $effectiveFrom,
                'rate_model' => $params['rate_model'] ?? null,
                // TODO(port): RateProperties::NormalizeRangesService — the
                // rate ranges normalization shared with the GraphQL layer.
                'rate_properties' => $params['rate_properties'] ?? [],
                'min_amount_cents' => $params['min_amount_cents'] ?? 0,
                'billing_interval_count' => $params['billing_interval_count'] ?? 1,
                'billing_interval_unit' => $params['billing_interval_unit'] ?? null,
                'applied_pricing_unit_conversion_rate' => $params['applied_pricing_unit_conversion_rate'] ?? null,
            ]);

            $errors = $rate->validateAttributes();
            if ($errors !== []) {
                $result->recordValidationFailure($errors)->raiseIfError();
            }

            // TODO(port): activity_loggable (rate_card.updated).
            $rate->save();

            $result->rate_card_rate = $rate;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
