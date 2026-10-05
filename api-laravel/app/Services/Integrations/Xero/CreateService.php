<?php

declare(strict_types=1);

namespace App\Services\Integrations\Xero;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Models\Integrations\XeroIntegration;
use App\Jobs\Integrations\Aggregator\PerformSyncJob;

/**
 * Port of Rails' Integrations::Xero::CreateService
 * (app/services/integrations/xero/create_service.rb) — Xero is a premium
 * integration (`xero`): both the license and the organization's
 * premium_integrations flag gate the creation, and the Nango sync is
 * triggered 2 seconds after the save.
 */
class CreateService extends \App\Services\BaseService
{
    public function __construct(
        ?object $user = null,
        public readonly ?string $name = null,
        public readonly ?string $code = null,
        public readonly ?string $organization_id = null,
        public readonly ?string $connection_id = null,
        public readonly ?bool $sync_credit_notes = null,
        public readonly ?bool $sync_invoices = null,
        public readonly ?bool $sync_payments = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('integration');

        $organization = Organization::query()->find($this->organization_id);

        if ($organization === null || ! $organization->xeroEnabled()) {
            return $result->notAllowedFailure('premium_integration_missing');
        }

        $errors = $this->validateParams();
        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $integration = new XeroIntegration([
            // Rails STI: the type column carries the Rails class name.
            'type' => XeroIntegration::XERO_TYPE,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'code' => $this->code,
            // Rails stores the secrets accessors into the encrypted secrets
            // column; the port keeps the plaintext JSON (see Integration).
            'secrets' => json_encode([
                'connection_id' => $this->connection_id,
            ]),
            'settings' => [
                'sync_credit_notes' => $this->sync_credit_notes ?? false,
                'sync_invoices' => $this->sync_invoices ?? false,
                'sync_payments' => $this->sync_payments ?? false,
            ],
        ]);

        $integration->save();

        // Rails: PerformSyncJob.set(wait: 2.seconds).perform_later(integration:).
        PerformSyncJob::dispatch($integration)->delay(now()->addSeconds(2));

        $result->integration = $integration;

        return $result;
    }

    /**
     * Rails: the model presence validations on connection_id + name, and the
     * code uniqueness scoped to the organization.
     *
     * @return array<string, list<string>>
     */
    private function validateParams(): array
    {
        $errors = [];

        foreach (['name', 'connection_id'] as $field) {
            if ($this->{$field} === null || $this->{$field} === '') {
                $errors[$field] = ['value_is_mandatory'];
            }
        }

        if (
            $this->code !== null
            && XeroIntegration::query()
                ->where('organization_id', $this->organization_id)
                ->where('code', $this->code)
                ->exists()
        ) {
            $errors['code'] = ['value_already_exists'];
        }

        return $errors;
    }
}
