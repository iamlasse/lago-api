<?php

declare(strict_types=1);

namespace App\Services\Integrations\Hubspot;

use App\Services\BaseResult;
use App\Models\Integrations\HubspotIntegration;
use App\Services\Integrations\Aggregator\AccountInformationService;

/**
 * Port of Rails' Integrations::Hubspot::SavePortalIdService
 * (app/services/integrations/hubspot/save_portal_id_service.rb) — fetches
 * the Nango account information once and stores the account id as the
 * portal id on the integration.
 */
class SavePortalIdService extends \App\Services\BaseService
{
    public function __construct(
        public readonly HubspotIntegration $integration,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of();

        if ($this->integration->portalId() !== null) {
            return $result;
        }

        $accountInformationResult = AccountInformationService::call(
            integration: $this->integration,
        );

        if ($accountInformationResult->failure()) {
            return $accountInformationResult;
        }

        $settings = (array) ($this->integration->settings ?? []);
        $settings['portal_id'] = $accountInformationResult->account_information->id;
        $this->integration->settings = $settings;
        $this->integration->save();

        return $result;
    }
}
