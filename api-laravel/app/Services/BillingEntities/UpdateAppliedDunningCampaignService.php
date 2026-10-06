<?php

declare(strict_types=1);

namespace App\Services\BillingEntities;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\DunningCampaign;

/**
 * Port of Rails' BillingEntities::UpdateAppliedDunningCampaignService
 * (app/services/billing_entities/update_applied_dunning_campaign_service.rb)
 * — swaps the dunning campaign applied to a billing entity, resetting the
 * customers' last dunning attempt so the new campaign starts fresh.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): Utils::SecurityLog.produce — ClickHouse security logs.
 */
class UpdateAppliedDunningCampaignService extends BaseService
{
    public function __construct(
        private readonly ?\App\Models\BillingEntity $billingEntity,
        private readonly ?string $appliedDunningCampaignId = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('billing_entity');

        if ($this->billingEntity === null) {
            return $result->notFoundFailure('billing_entity');
        }

        $billingEntity = $this->billingEntity;

        if ((string) $billingEntity->applied_dunning_campaign_id === (string) $this->appliedDunningCampaignId) {
            // Rails: `return if same` — the result carries no billing_entity.
            return $result;
        }

        try {
            // Rails: DunningCampaign.find(id) — RecordNotFound for unknown or
            // malformed ids (Laravel hands a malformed uuid to Postgres, so
            // the lookup answers null instead of raising).
            $dunningCampaign = $this->appliedDunningCampaignId !== null
                ? DunningCampaign::query()->whereKey($this->appliedDunningCampaignId)->first()
                : null;

            if ($this->appliedDunningCampaignId !== null && $dunningCampaign === null) {
                return $result->notFoundFailure('dunning_campaign');
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $result->notFoundFailure('dunning_campaign');
        } catch (\Illuminate\Database\QueryException) {
            return $result->notFoundFailure('dunning_campaign');
        }

        $billingEntity->resetCustomersLastDunningCampaignAttempt();
        $billingEntity->applied_dunning_campaign_id = $dunningCampaign?->id;
        $billingEntity->save();

        $result->billing_entity = $billingEntity;

        return $result;
    }
}
