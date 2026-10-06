<?php

declare(strict_types=1);

namespace App\Services\IntegrationMappings;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\IntegrationMappings\BaseMapping;

/**
 * Port of Rails' IntegrationMappings::DestroyService (app/services/
 * integration_mappings/destroy_service.rb).
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?BaseMapping $integration_mapping,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('integration_mapping');

        if ($this->integration_mapping === null) {
            return $result->notFoundFailure('integration_mapping');
        }

        $this->integration_mapping->delete();

        $result->integration_mapping = $this->integration_mapping;

        return $result;
    }
}
