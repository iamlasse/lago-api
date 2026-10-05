<?php

declare(strict_types=1);

namespace App\Services\Integrations\Hubspot;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Models\Integrations\HubspotIntegration;
use App\Jobs\Integrations\Hubspot\SavePortalIdJob;

/**
 * Port of Rails' Integrations::Hubspot::CreateService
 * (app/services/integrations/hubspot/create_service.rb) — HubSpot is a
 * premium integration gated by the organization's premium_integrations
 * flag; the connection id lives in the secrets, the sync flags and the
 * targeted object in the settings. On success the portal-id job is
 * enqueued.
 *
 * TODO(port): the SyncCustomObjectsAndPropertiesJob leg (the invoice and
 * subscription custom-object deploy) arrives with the invoices slice.
 * TODO(port): the security-log sink ("integration.created").
 */
class CreateService extends \App\Services\BaseService
{
    public function __construct(
        ?object $user = null,
        public readonly ?string $name = null,
        public readonly ?string $code = null,
        public readonly ?string $organization_id = null,
        public readonly ?string $connection_id = null,
        public readonly ?string $default_targeted_object = null,
        public readonly ?bool $sync_invoices = null,
        public readonly ?bool $sync_subscriptions = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('integration');

        $organization = Organization::query()->find($this->organization_id);

        if ($organization === null || ! $this->hubspotEnabled($organization)) {
            return $result->notAllowedFailure('premium_integration_missing');
        }

        $errors = $this->validateParams();
        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $integration = new HubspotIntegration([
            // Rails STI: the type column carries the Rails class name.
            'type' => HubspotIntegration::HUBSPOT_TYPE,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'code' => $this->code,
            // Rails stores the secrets accessors into the encrypted secrets
            // column; the port keeps the plaintext JSON (see Integration).
            'secrets' => json_encode(['connection_id' => $this->connection_id]),
            'settings' => [
                'default_targeted_object' => $this->default_targeted_object,
                'sync_invoices' => $this->sync_invoices,
                'sync_subscriptions' => $this->sync_subscriptions,
            ],
        ]);

        $integration->save();

        // TODO(port): Integrations::Aggregator::SyncCustomObjectsAndPropertiesJob
        // — the invoice/subscription custom-object deploy (invoices slice).
        SavePortalIdJob::dispatch($integration);

        $result->integration = $integration;

        return $result;
    }

    /** Rails: `organization.hubspot_enabled?` — License.premium? + flag. */
    private function hubspotEnabled(Organization $organization): bool
    {
        return \App\Support\License::premium()
            && in_array('hubspot', (array) ($organization->premium_integrations ?? []), true);
    }

    /**
     * Rails: the model presence validations on the connection secret and the
     * default targeted object, plus name and the code uniqueness scoped to
     * the organization.
     *
     * @return array<string, list<string>>
     */
    private function validateParams(): array
    {
        $errors = [];

        foreach (['name', 'connection_id', 'default_targeted_object'] as $field) {
            if ($this->{$field} === null || $this->{$field} === '') {
                $errors[$field] = ['value_is_mandatory'];
            }
        }

        if (
            $this->code !== null
            && HubspotIntegration::query()
                ->where('organization_id', $this->organization_id)
                ->where('code', $this->code)
                ->exists()
        ) {
            $errors['code'] = ['value_already_exists'];
        }

        return $errors;
    }
}
