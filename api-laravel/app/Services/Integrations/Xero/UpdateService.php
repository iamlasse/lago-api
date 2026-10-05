<?php

declare(strict_types=1);

namespace App\Services\Integrations\Xero;

use App\Services\BaseResult;
use App\Models\Integrations\XeroIntegration;

/**
 * Port of Rails' Integrations::Xero::UpdateService
 * (app/services/integrations/xero/update_service.rb) — name/code/sync flags
 * in params-key order, behind the same premium-integration gate as the
 * create.
 */
class UpdateService extends \App\Services\BaseService
{
    public function __construct(
        public readonly ?XeroIntegration $integration,
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

        if (! $this->integration->organization->xeroEnabled()) {
            return $result->notAllowedFailure('premium_integration_missing');
        }

        $integration = $this->integration;

        if (array_key_exists('name', $this->params)) {
            $integration->name = $this->params['name'];
        }

        if (array_key_exists('code', $this->params)) {
            $integration->code = $this->params['code'];
        }

        $settings = $integration->settings ?? [];

        foreach (['sync_credit_notes', 'sync_invoices', 'sync_payments'] as $flag) {
            if (array_key_exists($flag, $this->params)) {
                $settings[$flag] = $this->params[$flag];
            }
        }

        $integration->settings = $settings;

        $errors = $this->validateParams($integration);
        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $integration->save();

        $result->integration = $integration;

        return $result;
    }

    /**
     * Rails: the model validations on save — name/connection_id presence and
     * the code uniqueness scoped to the organization.
     *
     * @return array<string, list<string>>
     */
    private function validateParams(XeroIntegration $integration): array
    {
        $errors = [];

        if (($integration->name ?? '') === '') {
            $errors['name'] = ['value_is_mandatory'];
        }

        if (($integration->connectionId() ?? '') === '') {
            $errors['connection_id'] = ['value_is_mandatory'];
        }

        $codeExists = XeroIntegration::query()
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
