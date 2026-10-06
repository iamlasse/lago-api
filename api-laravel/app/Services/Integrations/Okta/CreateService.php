<?php

declare(strict_types=1);

namespace App\Services\Integrations\Okta;

use App\Support\License;
use App\Models\Integration;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Models\Integrations\OktaIntegration;

/**
 * Port of Rails' Integrations::Okta::CreateService
 * (app/services/integrations/okta/create_service.rb) — Okta is a premium
 * integration ("okta"); creating one enables the okta authentication method.
 *
 * The model's presence/uniqueness validations (client_secret, client_id,
 * domain, organization_name; settings->>'domain' uniqueness across Okta
 * integrations) are ported here — the frozen OktaIntegration model carries
 * no validation hooks.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): Utils::SecurityLog.produce("integration.created").
 */
class CreateService extends \App\Services\BaseService
{
    public function __construct(
        ?object $user = null,
        public readonly ?string $organization_id = null,
        public readonly ?string $client_id = null,
        public readonly ?string $client_secret = null,
        public readonly ?string $domain = null,
        public readonly ?string $organization_name = null,
        public readonly ?string $host = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('integration');

        $organization = Organization::query()->find($this->organization_id);

        // Rails: organization.okta_enabled? — License.premium? &&
        // premium_integrations.include?("okta") (the Organization model is
        // read-only in this slice, so the gate stays inline).
        if (
            $organization === null
            || ! License::premium()
            || ! in_array('okta', (array) ($organization->premium_integrations ?? []), true)
        ) {
            return $result->notAllowedFailure('premium_integration_missing');
        }

        $errors = $this->validate();

        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $integration = new OktaIntegration([
            // Rails STI: the type column carries the Rails class name.
            'type' => Integration::OKTA_TYPE,
            'organization_id' => $organization->id,
            'name' => 'Okta Integration',
            'code' => 'okta',
            // Rails stores the secrets accessors into the encrypted secrets
            // column; the port keeps the plaintext JSON (see Integration).
            'secrets' => json_encode(['client_secret' => $this->client_secret]),
            'settings' => [
                'client_id' => $this->client_id,
                'domain' => $this->domain,
                'organization_name' => $this->organization_name,
                'host' => $this->host,
            ],
        ]);

        $integration->save();

        // Rails: organization.enable_okta_authentication! — the premium
        // gate is the okta_enabled? one above.
        $methods = (array) ($organization->authentication_methods ?? []);

        if (! in_array('okta', $methods, true)) {
            $organization->authentication_methods = array_values(array_merge($methods, ['okta']));
            $organization->save();
        }

        $result->integration = $integration;

        return $result;
    }

    /**
     * @return array<string, list<string>>
     */
    private function validate(): array
    {
        $errors = [];

        foreach (['client_id', 'client_secret', 'domain', 'organization_name'] as $field) {
            if ($this->{$field} === null || $this->{$field} === '') {
                $errors[$field] = ["can't be blank"];
            }
        }

        if (($this->domain ?? '') !== '' && $this->domainTaken()) {
            $errors['domain'] = ['domain_not_unique'];
        }

        return $errors;
    }

    /** Rails: domain_uniqueness across every Okta integration. */
    private function domainTaken(): bool
    {
        return OktaIntegration::query()
            ->whereRaw("settings->>'domain' IS NOT NULL")
            ->whereRaw("settings->>'domain' = ?", [$this->domain])
            ->exists();
    }
}
