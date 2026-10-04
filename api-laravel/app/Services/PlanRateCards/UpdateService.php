<?php

declare(strict_types=1);

namespace App\Services\PlanRateCards;

use App\Models\PlanRateCard;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;

use function array_key_exists;

/**
 * Port of Rails' PlanRateCards::UpdateService
 * (app/services/plan_rate_cards/update_service.rb) — edits a plan's rate
 * card entry. A plan with contracts is immutable: pricing changes go
 * through a new plan and a contract migration.
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?PlanRateCard $planRateCard,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('plan_rate_card');
        $planRateCard = $this->planRateCard;

        try {
            if ($planRateCard === null) {
                return $result->notFoundFailure('applied_rate_card');
            }

            if ($planRateCard->catalogPlan->attachedToContracts()) {
                return $result->singleValidationFailure('plan_locked', 'plan');
            }

            if (array_key_exists('units', $this->params)) {
                $planRateCard->units = $this->params['units'];
            }

            $errors = $planRateCard->validateAttributes();
            if ($errors !== []) {
                $result->recordValidationFailure($errors)->raiseIfError();
            }

            $planRateCard->save();

            $result->plan_rate_card = $planRateCard;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
