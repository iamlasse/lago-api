<?php

declare(strict_types=1);

namespace App\Services\IntegrationCollectionMappings;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\IntegrationCollectionMappings\BaseCollectionMapping;

use function array_key_exists;

/**
 * Port of Rails' IntegrationCollectionMappings::UpdateService (app/services/
 * integration_collection_mappings/update_service.rb).
 */
class UpdateService extends BaseService
{
    /**
     * @param  \App\Models\IntegrationCollectionMappings\BaseCollectionMapping|null  $integration_collection_mapping
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        private readonly ?BaseCollectionMapping $integration_collection_mapping,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('integration_collection_mapping');
        $mapping = $this->integration_collection_mapping;

        if ($mapping === null) {
            return $result->notFoundFailure('integration_collection_mapping');
        }

        if (array_key_exists('external_id', $this->params)) {
            $mapping->external_id = $this->params['external_id'];
        }
        if (array_key_exists('external_account_code', $this->params)) {
            $mapping->external_account_code = $this->params['external_account_code'];
        }
        if (array_key_exists('external_name', $this->params)) {
            $mapping->external_name = $this->params['external_name'];
        }
        if (array_key_exists('tax_nexus', $this->params)) {
            $mapping->tax_nexus = $this->params['tax_nexus'];
        }
        if (array_key_exists('tax_code', $this->params)) {
            $mapping->tax_code = $this->params['tax_code'];
        }
        if (array_key_exists('tax_type', $this->params)) {
            $mapping->tax_type = $this->params['tax_type'];
        }
        if (array_key_exists('currencies', $this->params)) {
            $mapping->currencies = $this->params['currencies'];
        }

        $errors = $mapping->validateAttributes();

        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $mapping->save();

        $result->integration_collection_mapping = $mapping;

        return $result;
    }
}
