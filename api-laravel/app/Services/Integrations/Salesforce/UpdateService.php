<?php

declare(strict_types=1);

namespace App\Services\Integrations\Salesforce;

use App\Services\BaseResult;
use App\Models\Integrations\SalesforceIntegration;

/**
 * Port of Rails' Integrations::Salesforce::UpdateService
 * (app/services/integrations/salesforce/update_service.rb) — name/code/
 * instance_id updated only for the params keys present.
 *
 * TODO(port): the security-log sink ("integration.updated").
 */
class UpdateService extends \App\Services\BaseService
{
    public function __construct(
        public readonly ?SalesforceIntegration $integration,
        /** @var array<string, mixed> */
        public readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('integration');

        if ($this->integration === null) {
            return $result->notFoundFailure('integration');
        }

        $integration = $this->integration;

        $organization = $integration->organization;

        if ($organization === null || ! \App\Support\License::premium()
            || ! in_array('salesforce', (array) ($organization->premium_integrations ?? []), true)
        ) {
            return $result->notAllowedFailure('premium_integration_missing');
        }

        if (array_key_exists('name', $this->params)) {
            $integration->name = $this->params['name'];
        }

        if (array_key_exists('code', $this->params)) {
            $integration->code = $this->params['code'];
        }

        if (array_key_exists('instance_id', $this->params)) {
            $settings = (array) ($integration->settings ?? []);
            $settings['instance_id'] = $this->params['instance_id'];
            $integration->settings = $settings;
        }

        $errors = $this->validateParams($integration);
        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $integration->save();

        $result->integration = $integration;

        return $result;
    }

    /**
     * Rails: the model presence validations on instance_id, and the code
     * uniqueness scoped to the organization.
     *
     * @return array<string, list<string>>
     */
    private function validateParams(SalesforceIntegration $integration): array
    {
        $errors = [];

        if (($integration->name ?? '') === '') {
            $errors['name'] = ['value_is_mandatory'];
        }

        if (($integration->getFromSettings('instance_id') ?? '') === '') {
            $errors['instance_id'] = ['value_is_mandatory'];
        }

        $codeExists = SalesforceIntegration::query()
            ->where('organization_id', $integration->organization_id)
            ->where('code', $integration->code)
            ->where('id', '!=', $integration->id)
            ->exists();

        if ($codeExists) {
            $errors['code'] = ['value_already_exists'];
        }

        return $errors;
    }
}
