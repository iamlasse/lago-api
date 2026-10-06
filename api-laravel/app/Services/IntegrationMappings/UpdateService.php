<?php

declare(strict_types=1);

namespace App\Services\IntegrationMappings;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\IntegrationMappings\BaseMapping;

use function array_key_exists;

/**
 * Port of Rails' IntegrationMappings::UpdateService (app/services/
 * integration_mappings/update_service.rb).
 */
class UpdateService extends BaseService
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        private readonly ?BaseMapping $integration_mapping,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('integration_mapping');
        $integrationMapping = $this->integration_mapping;

        if ($integrationMapping === null) {
            return $result->notFoundFailure('integration_mapping');
        }

        if (array_key_exists('external_id', $this->params)) {
            $integrationMapping->external_id = $this->params['external_id'];
        }
        if (array_key_exists('external_account_code', $this->params)) {
            $integrationMapping->external_account_code = $this->params['external_account_code'];
        }
        if (array_key_exists('external_name', $this->params)) {
            $integrationMapping->external_name = $this->params['external_name'];
        }

        $errors = $integrationMapping->validateAttributes();

        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $integrationMapping->save();

        $result->integration_mapping = $integrationMapping;

        return $result;
    }
}
