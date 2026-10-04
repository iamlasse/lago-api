<?php

declare(strict_types=1);

namespace App\Services\Webhooks\DunningCampaigns;

use App\Services\Webhooks\BaseService;
use App\Serializers\V1\DunningCampaignFinishedSerializer;

/**
 * Port of Rails' Webhooks::DunningCampaigns::FinishedService
 * (app/services/webhooks/dunning_campaigns/finished_service.rb) — the
 * "dunning_campaign.finished" customer event (object = the Customer;
 * the campaign's code travels in options).
 */
class FinishedService extends BaseService
{
    /** @return array<string, mixed> */
    protected function objectSerializer(): array
    {
        return (new DunningCampaignFinishedSerializer(
            $this->object,
            [
                'root_name' => $this->objectType(),
                'dunning_campaign_code' => $this->options['dunning_campaign_code'] ?? null,
            ],
        ))->serialize();
    }

    protected function webhookType(): string
    {
        return 'dunning_campaign.finished';
    }

    protected function objectType(): string
    {
        return 'dunning_campaign';
    }
}
