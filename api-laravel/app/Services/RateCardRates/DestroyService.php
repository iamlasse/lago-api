<?php

declare(strict_types=1);

namespace App\Services\RateCardRates;

use App\Models\RateCardRate;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' RateCardRates::DestroyService
 * (app/services/rate_card_rates/destroy_service.rb) — only pending rates
 * can be deleted; active and terminated rates are kept for audit.
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?RateCardRate $rateCardRate,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('rate_card_rate');
        $rateCardRate = $this->rateCardRate;

        try {
            if ($rateCardRate === null) {
                return $result->notFoundFailure('rate_card_rate');
            }

            if (! $rateCardRate->isPending()) {
                return $result->singleValidationFailure('only_pending_rates_can_be_deleted', 'status');
            }

            // TODO(port): activity_loggable (rate_card.updated).
            $rateCardRate->delete();

            $result->rate_card_rate = $rateCardRate;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
