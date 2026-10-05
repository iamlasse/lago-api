<?php

declare(strict_types=1);

namespace App\Services\Integrations\Avalara;

use App\Models\Integration;
use App\Services\BaseResult;
use App\Services\Integrations\Aggregator\Taxes\Avalara\FetchCompanyIdService as AggregatorFetchCompanyIdService;

/**
 * Port of Rails' Integrations::Avalara::FetchCompanyIdService
 * (app/services/integrations/avalara/fetch_company_id_service.rb) — the
 * wrapper stamping the fetched Nango company id onto the integration.
 */
class FetchCompanyIdService extends \App\Services\BaseService
{
    public function __construct(public readonly ?Integration $integration)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of();

        if ($this->integration?->type !== Integration::AVALARA_TYPE) {
            return $result;
        }

        $integration = $this->integration;

        if ($integration->getFromSettings('company_id') !== null) {
            return $result;
        }

        $providerResult = AggregatorFetchCompanyIdService::call(integration: $integration);

        if ($providerResult->success()) {
            $settings = (array) ($integration->settings ?? []);
            $settings['company_id'] = $providerResult->company['id'];
            $integration->settings = $settings;
            $integration->save();
        }

        return $providerResult;
    }
}
