<?php

declare(strict_types=1);

namespace App\Jobs\Integrations\Hubspot;

use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Models\Integrations\HubspotIntegration;
use App\Services\Integrations\Hubspot\SavePortalIdService;

/**
 * Port of Rails' Integrations::Hubspot::SavePortalIdJob
 * (app/jobs/integrations/hubspot/save_portal_id_job.rb).
 */
class SavePortalIdJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly HubspotIntegration $integration,
    ) {
        $this->onQueue('integrations');
    }

    public function handle(): void
    {
        SavePortalIdService::call(integration: $this->integration);
    }
}
