<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Services\BaseResult;

/**
 * Port of Rails' Integrations::DestroyService
 * (app/services/integrations/destroy_service.rb) — the generic destroy all
 * non-SSO integration types fall back to (Okta/Entra have their own
 * destroy services in the SSO slice).
 *
 * TODO(port): Utils::SecurityLog.produce("integration.deleted") — the
 * security-logs sink is not ported yet.
 */
class DestroyService extends \App\Services\BaseService
{
    public function __construct(public readonly ?Integration $integration)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('integration');

        if ($this->integration === null) {
            return $result->notFoundFailure('integration');
        }

        $this->integration->delete();

        $result->integration = $this->integration;

        return $result;
    }
}
