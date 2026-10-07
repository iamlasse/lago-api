<?php

declare(strict_types=1);

namespace App\Services\Integrations\Netsuite;

use App\Services\BaseResult;
use App\Models\Integrations\NetsuiteIntegration;
use App\Jobs\Integrations\Aggregator\PerformSyncJob;
use App\Jobs\Integrations\Aggregator\SendRestletEndpointJob;

/**
 * Port of Rails' Integrations::Netsuite::UpdateService
 * (app/services/integrations/netsuite/update_service.rb) — a changed
 * script_endpoint_url re-registers the restlet endpoint and re-triggers the
 * (items-less) Nango sync.
 */
class UpdateService extends \App\Services\BaseService
{
    public function __construct(
        public readonly ?NetsuiteIntegration $integration,
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

        if (! $this->integration->organization->netsuiteEnabled()) {
            return $result->notAllowedFailure('premium_integration_missing');
        }

        $integration = $this->integration;
        $oldScriptUrl = $integration->scriptEndpointUrl();

        if (array_key_exists('name', $this->params)) {
            $integration->name = $this->params['name'];
        }

        if (array_key_exists('code', $this->params)) {
            $integration->code = $this->params['code'];
        }

        $secrets = json_decode((string) ($integration->secrets ?? '{}'), true) ?: [];

        if (array_key_exists('connection_id', $this->params)) {
            $secrets['connection_id'] = $this->params['connection_id'];
        }

        if (array_key_exists('client_secret', $this->params)) {
            $secrets['client_secret'] = $this->params['client_secret'];
        }

        if (array_key_exists('token_secret', $this->params)) {
            $secrets['token_secret'] = $this->params['token_secret'];
        }

        $integration->secrets = json_encode($secrets);

        $settings = $integration->settings ?? [];

        foreach (['script_endpoint_url', 'sync_credit_notes', 'sync_invoices', 'sync_payments'] as $field) {
            if (array_key_exists($field, $this->params)) {
                $settings[$field] = $this->params[$field];
            }
        }

        if (array_key_exists('client_id', $this->params)) {
            $settings['client_id'] = $this->params['client_id'];
        }

        if (array_key_exists('token_id', $this->params)) {
            $settings['token_id'] = $this->params['token_id'];
        }

        $integration->settings = $settings;

        if (array_key_exists('account_id', $this->params)) {
            // Rails: the account_id= override normalizes into settings.
            $integration->setAccountId($this->params['account_id']);
        }

        $errors = $this->validateParams($integration);
        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $integration->save();

        if ($integration->scriptEndpointUrl() !== $oldScriptUrl) {
            dispatch(new \App\Jobs\Integrations\Aggregator\SendRestletEndpointJob($integration));

            // Rails: PerformSyncJob.set(wait: 2.seconds).perform_later(integration:, sync_items: false).
            dispatch(new \App\Jobs\Integrations\Aggregator\PerformSyncJob($integration, false))->delay(now()->addSeconds(2));
        }

        $result->integration = $integration;

        return $result;
    }

    /**
     * Rails: the model validations on save — the presence of the
     * secrets/settings accessors + name, and the code uniqueness scoped to
     * the organization.
     *
     * @return array<string, list<string>>
     */
    private function validateParams(NetsuiteIntegration $integration): array
    {
        $errors = [];

        if (($integration->name ?? '') === '') {
            $errors['name'] = ['value_is_mandatory'];
        }

        foreach ([
            'connection_id' => $integration->connectionId(),
            'client_id' => $integration->clientId(),
            'client_secret' => $integration->clientSecret(),
            'account_id' => $integration->accountId(),
            'script_endpoint_url' => $integration->scriptEndpointUrl(),
        ] as $field => $value) {
            if (($value ?? '') === '') {
                $errors[$field] = ['value_is_mandatory'];
            }
        }

        $codeExists = NetsuiteIntegration::query()
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
