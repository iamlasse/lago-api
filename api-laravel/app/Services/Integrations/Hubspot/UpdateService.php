<?php

declare(strict_types=1);

namespace App\Services\Integrations\Hubspot;

use App\Services\BaseResult;
use App\Models\Integrations\HubspotIntegration;

/**
 * Port of Rails' Integrations::Hubspot::UpdateService
 * (app/services/integrations/hubspot/update_service.rb) — name/code/
 * targeted object/sync flags updated only for the params keys present; the
 * connection secret never rotates on update.
 *
 * TODO(port): the security-log sink ("integration.updated").
 */
class UpdateService extends \App\Services\BaseService
{
    public function __construct(
        public readonly ?HubspotIntegration $integration,
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
            || ! in_array('hubspot', (array) ($organization->premium_integrations ?? []), true)
        ) {
            return $result->notAllowedFailure('premium_integration_missing');
        }

        if (array_key_exists('name', $this->params)) {
            $integration->name = $this->params['name'];
        }

        if (array_key_exists('code', $this->params)) {
            $integration->code = $this->params['code'];
        }

        if (array_key_exists('default_targeted_object', $this->params)) {
            $settings = (array) ($integration->settings ?? []);
            $settings['default_targeted_object'] = $this->params['default_targeted_object'];
            $integration->settings = $settings;
        }

        if (array_key_exists('sync_invoices', $this->params)) {
            $settings = (array) ($integration->settings ?? []);
            $settings['sync_invoices'] = $this->params['sync_invoices'];
            $integration->settings = $settings;
        }

        if (array_key_exists('sync_subscriptions', $this->params)) {
            $settings = (array) ($integration->settings ?? []);
            $settings['sync_subscriptions'] = $this->params['sync_subscriptions'];
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
     * Rails: the model presence validations on the connection secret and the
     * default targeted object, plus the code uniqueness scoped to the
     * organization.
     *
     * @return array<string, list<string>>
     */
    private function validateParams(HubspotIntegration $integration): array
    {
        $errors = [];

        if (($integration->name ?? '') === '') {
            $errors['name'] = ['value_is_mandatory'];
        }

        $connectionId = $integration->getFromSecrets('connection_id');

        if ($connectionId === null || $connectionId === '') {
            $errors['connection_id'] = ['value_is_mandatory'];
        }

        if (($integration->defaultTargetedObject() ?? '') === '') {
            $errors['default_targeted_object'] = ['value_is_mandatory'];
        }

        $codeExists = HubspotIntegration::query()
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
