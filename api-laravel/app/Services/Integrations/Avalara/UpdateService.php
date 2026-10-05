<?php

declare(strict_types=1);

namespace App\Services\Integrations\Avalara;

use App\Services\BaseResult;
use App\Models\Integrations\AvalaraIntegration;

/**
 * Port of Rails' Integrations::Avalara::UpdateService
 * (app/services/integrations/avalara/update_service.rb) — only name, code
 * and company_code are updatable (the account id, license key and
 * connection id are fixed at creation); gated on the organization's
 * avalara premium flag.
 */
class UpdateService extends \App\Services\BaseService
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public readonly ?AvalaraIntegration $integration,
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

        if (! $this->integration->organization->avalaraEnabled()) {
            return $result->notAllowedFailure('premium_integration_missing');
        }

        $integration = $this->integration;

        if (array_key_exists('name', $this->params)) {
            $integration->name = $this->params['name'];
        }

        if (array_key_exists('code', $this->params)) {
            $integration->code = $this->params['code'];
        }

        if (array_key_exists('company_code', $this->params)) {
            $settings = (array) ($integration->settings ?? []);
            $settings['company_code'] = $this->params['company_code'];
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
     * @return array<string, list<string>>
     */
    private function validateParams(AvalaraIntegration $integration): array
    {
        $errors = [];

        if (($integration->name ?? '') === '') {
            $errors['name'] = ['value_is_mandatory'];
        }

        if (($integration->getFromSettings('company_code') ?? '') === '') {
            $errors['company_code'] = ['value_is_mandatory'];
        }

        $codeExists = AvalaraIntegration::query()
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
