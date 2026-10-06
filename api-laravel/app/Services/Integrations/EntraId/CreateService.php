<?php

declare(strict_types=1);

namespace App\Services\Integrations\EntraId;

use App\Support\License;
use App\Models\Integration;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Models\Integrations\EntraIdIntegration;

/**
 * Port of Rails' Integrations::EntraId::CreateService
 * (app/services/integrations/entra_id/create_service.rb) — Entra ID is a
 * premium integration ("entra_id"); creating one enables the entra_id
 * authentication method.
 *
 * The model validations (client_secret/client_id/domain/tenant_id presence,
 * settings->>'domain' uniqueness, the tenant_id/host URL-segment format) are
 * ported here — the frozen EntraIdIntegration model carries no validation
 * hooks.
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
        public readonly ?string $tenant_id = null,
        public readonly ?string $host = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('integration');

        $organization = Organization::query()->find($this->organization_id);

        if (
            $organization === null
            || ! License::premium()
            || ! in_array('entra_id', (array) ($organization->premium_integrations ?? []), true)
        ) {
            return $result->notAllowedFailure('premium_integration_missing');
        }

        $errors = $this->validate();

        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $integration = new EntraIdIntegration([
            // Rails STI: the type column carries the Rails class name.
            'type' => Integration::ENTRA_ID_TYPE,
            'organization_id' => $organization->id,
            'name' => 'Entra ID Integration',
            'code' => 'entra_id',
            'secrets' => json_encode(['client_secret' => $this->client_secret]),
            'settings' => [
                'client_id' => $this->client_id,
                'domain' => $this->domain,
                'tenant_id' => $this->tenant_id,
                'host' => $this->host,
            ],
        ]);

        $integration->save();

        // Rails: organization.enable_entra_id_authentication!.
        $methods = (array) ($organization->authentication_methods ?? []);

        if (! in_array('entra_id', $methods, true)) {
            $organization->authentication_methods = array_values(array_merge($methods, ['entra_id']));
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

        foreach (['client_id', 'client_secret', 'domain', 'tenant_id'] as $field) {
            if ($this->{$field} === null || $this->{$field} === '') {
                $errors[$field] = ["can't be blank"];
            }
        }

        if (($this->domain ?? '') !== ''
            && EntraIdIntegration::query()
                ->whereRaw("settings->>'domain' IS NOT NULL")
                ->whereRaw("settings->>'domain' = ?", [$this->domain])
                ->exists()
        ) {
            $errors['domain'] = ['domain_not_unique'];
        }

        // Rails: tenant_id_and_host_format — both are interpolated into the
        // Entra authorize/token URLs, so only safe URL segments pass.
        $urlSegment = '/\A[a-zA-Z0-9.-]+\z/';

        if (($this->tenant_id ?? '') !== '' && preg_match($urlSegment, (string) $this->tenant_id) !== 1) {
            $errors['tenant_id'] = ['tenant_id_invalid'];
        }

        if (($this->host ?? '') !== '' && preg_match($urlSegment, (string) $this->host) !== 1) {
            $errors['host'] = ['host_invalid'];
        }

        return $errors;
    }
}
