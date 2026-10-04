<?php

declare(strict_types=1);

namespace App\Services\RateCards;

use App\Models\RateCard;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' RateCards::DestroyService
 * (app/services/rate_cards/destroy_service.rb).
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?RateCard $rateCard,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('rate_card');
        $rateCard = $this->rateCard;

        try {
            if ($rateCard === null) {
                return $result->notFoundFailure('rate_card');
            }

            if ($rateCard->attachedToPlanOrSubscription()) {
                return $result->singleValidationFailure('attached_to_plan_or_subscription', 'rate_card');
            }

            DB::transaction(function () use ($rateCard): void {
                // TODO(port): activity_loggable (rate_card.deleted).
                $rateCard->rates()->whereNull('deleted_at')->update(['deleted_at' => now()]);
                $rateCard->delete();
            });

            $result->rate_card = $rateCard;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
