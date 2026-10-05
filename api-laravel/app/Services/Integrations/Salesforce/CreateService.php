<?php

declare(strict_types=1);

namespace App\Services\Integrations\Salesforce;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Models\Integrations\SalesforceIntegration;

/**
 * Port of Rails' Integrations::Salesforce::CreateService
 * (app/services/integrations/salesforce/create_service.rb) — Salesforce is
 * a premium integration gated by the organization's premium_integrations
 * flag; the instance id is stored in the settings.
 *
 * TODO(port): the security-log sink ("integration.created") — Rails logs the
 * creation through Utils::SecurityLog, which is not ported yet.
 */
class CreateService extends \App\Services\BaseService
{
    public function __construct(
        ?object $user = null,
        public readonly ?string $name = null,
        public readonly ?string $code = null,
        public readonly ?string $organization_id = null,
        public readonly ?string $instance_id = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('integration');

        $organization = Organization::query()->find($this->organization_id);

        if ($organization === null || ! $this->salesforceEnabled($organization)) {
            return $result->notAllowedFailure('premium_integration_missing');
        }

        $errors = $this->validateParams();
        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $integration = new SalesforceIntegration([
            // Rails STI: the type column carries the Rails class name.
            'type' => SalesforceIntegration::SALESFORCE_TYPE,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'code' => $this->code,
            'settings' => ['instance_id' => $this->instance_id],
        ]);

        $integration->save();

        $result->integration = $integration;

        return $result;
    }

    /** Rails: `organization.salesforce_enabled?` — License.premium? + flag. */
    private function salesforceEnabled(Organization $organization): bool
    {
        return \App\Support\License::premium()
            && in_array('salesforce', (array) ($organization->premium_integrations ?? []), true);
    }

    /**
     * Rails: the model presence validations on instance_id, and the code
     * uniqueness scoped to the organization.
     *
     * @return array<string, list<string>>
     */
    private function validateParams(): array
    {
        $errors = [];

        if ($this->name === null || $this->name === '') {
            $errors['name'] = ['value_is_mandatory'];
        }

        if ($this->instance_id === null || $this->instance_id === '') {
            $errors['instance_id'] = ['value_is_mandatory'];
        }

        if (
            $this->code !== null
            && SalesforceIntegration::query()
                ->where('organization_id', $this->organization_id)
                ->where('code', $this->code)
                ->exists()
        ) {
            $errors['code'] = ['value_already_exists'];
        }

        return $errors;
    }
}
