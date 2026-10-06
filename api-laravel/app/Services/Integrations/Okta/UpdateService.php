<?php

declare(strict_types=1);

namespace App\Services\Integrations\Okta;

use App\Support\License;
use App\Services\BaseResult;
use App\Models\Integrations\OktaIntegration;

/**
 * Port of Rails' Integrations::Okta::UpdateService
 * (app/services/integrations/okta/update_service.rb) — present-key updates
 * over the settings/secrets accessors; an obfuscated client_secret (the
 * wire's "••••••••…" form) is ignored.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): Utils::SecurityLog.produce("integration.updated").
 */
class UpdateService extends \App\Services\BaseService
{
    public function __construct(
        public readonly ?OktaIntegration $integration,
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

        $organization = $this->integration->organization;

        if (
            $organization === null
            || ! License::premium()
            || ! in_array('okta', (array) ($organization->premium_integrations ?? []), true)
        ) {
            return $result->notAllowedFailure('premium_integration_missing');
        }

        $settings = (array) ($this->integration->settings ?? []);

        foreach (['client_id', 'domain', 'organization_name', 'host'] as $field) {
            if (array_key_exists($field, $this->params)) {
                $settings[$field] = $this->params[$field];
            }
        }

        $this->integration->settings = $settings;

        if ($this->clientSecretUpdate()) {
            $this->integration->secrets = json_encode(array_merge(
                (array) (json_decode((string) ($this->integration->secrets ?? '{}'), true) ?: []),
                ['client_secret' => $this->params['client_secret']],
            ));
        }

        $errors = $this->validate();

        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $this->integration->save();

        $result->integration = $this->integration;

        return $result;
    }

    /**
     * Rails: client_secret_update? — the masked placeholder the wire echoes
     * back is never written.
     */
    private function clientSecretUpdate(): bool
    {
        $secret = $this->params['client_secret'] ?? null;

        return $secret !== null
            && $secret !== ''
            && preg_match('/\A•{8}…/u', (string) $secret) !== 1;
    }

    /**
     * @return array<string, list<string>>
     */
    private function validate(): array
    {
        $errors = [];

        foreach (['client_id' => $this->integration->clientId(), 'domain' => $this->integration->domain(), 'organization_name' => $this->integration->organizationName()] as $field => $value) {
            if (($value ?? '') === '') {
                $errors[$field] = ["can't be blank"];
            }
        }

        if ($this->integration->getFromSecrets('client_secret') === null) {
            $errors['client_secret'] = ["can't be blank"];
        }

        $domain = $this->integration->domain();

        if (($domain ?? '') !== '' && $this->domainTaken((string) $domain)) {
            $errors['domain'] = ['domain_not_unique'];
        }

        return $errors;
    }

    /** Rails: domain_uniqueness — other Okta integrations excluded. */
    private function domainTaken(string $domain): bool
    {
        return OktaIntegration::query()
            ->whereRaw("settings->>'domain' IS NOT NULL")
            ->whereRaw("settings->>'domain' = ?", [$domain])
            ->where('id', '!=', $this->integration->id)
            ->exists();
    }
}
