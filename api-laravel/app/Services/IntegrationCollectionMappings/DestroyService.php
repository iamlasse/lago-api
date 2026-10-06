<?php

declare(strict_types=1);

namespace App\Services\IntegrationCollectionMappings;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\IntegrationCollectionMappings\BaseCollectionMapping;

/**
 * Port of Rails' IntegrationCollectionMappings::DestroyService (app/services/
 * integration_collection_mappings/destroy_service.rb).
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?BaseCollectionMapping $integration_collection_mapping,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('integration_collection_mapping');

        if ($this->integration_collection_mapping === null) {
            return $result->notFoundFailure('integration_collection_mapping');
        }

        $this->integration_collection_mapping->delete();

        $result->integration_collection_mapping = $this->integration_collection_mapping;

        return $result;
    }
}
