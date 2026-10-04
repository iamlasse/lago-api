<?php

declare(strict_types=1);

namespace App\Services\DunningCampaigns;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\DunningCampaign;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' DunningCampaigns::DestroyService
 * (app/services/dunning_campaigns/destroy_service.rb): not_found when the
 * campaign is gone, forbidden unless the organization has the auto-dunning
 * entitlement, then in one transaction — reset the customers' attempt
 * bookkeeping, discard the campaign (SoftDeletes), stamp the thresholds'
 * deleted_at and detach the customers / billing entities / payment requests.
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?DunningCampaign $dunningCampaign,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('dunning_campaign');

        if ($this->dunningCampaign === null) {
            return $result->notFoundFailure('dunning_campaign');
        }

        if (! $this->dunningCampaign->organization->autoDunningEnabled()) {
            return $result->forbiddenFailure();
        }

        $campaign = $this->dunningCampaign;

        DB::transaction(function () use ($campaign): void {
            // Rails: reset_customers_last_attempt (explicit + fallback
            // customers), then discard!.
            $campaign->resetCustomersLastAttempt();

            $campaign->delete(); // Rails: discard! — the SoftDeletes stamp.

            // Rails: thresholds.update_all(deleted_at: Time.current).
            $campaign->thresholds()->withTrashed()->toBase()
                ->update(['deleted_at' => now()]);

            // Rails: dependent: :nullify on customers / payment_requests,
            // and the billing entities' applied campaign reset.
            $campaign->customers()->toBase()
                ->update(['applied_dunning_campaign_id' => null]);

            $campaign->billingEntities()->toBase()
                ->update(['applied_dunning_campaign_id' => null]);
        });

        $result->dunning_campaign = $campaign;

        return $result;
    }
}
