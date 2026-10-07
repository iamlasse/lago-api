<?php

declare(strict_types=1);

namespace App\Services\Integrations\Netsuite;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Models\Integrations\NetsuiteIntegration;
use App\Jobs\Integrations\Aggregator\PerformSyncJob;
use App\Jobs\Integrations\Aggregator\SendRestletEndpointJob;

/**
 * Port of Rails' Integrations::Netsuite::CreateService
 * (app/services/integrations/netsuite/create_service.rb) — the Netsuite TBA
 * credentials split across the settings/secrets columns; the restlet
 * endpoint registration and the (items-less) Nango sync fire right after
 * the save.
 */
class CreateService extends \App\Services\BaseService
{
    /** @var array<string, mixed> */
    public readonly array $params;

    public function __construct(
        ?object $user = null,
        /** @var array<string, mixed> */
        array $params = [],
    ) {
        parent::__construct();

        $this->params = $params;
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('integration');

        $params = $this->params;
        $organization = Organization::query()->find($params['organization_id'] ?? null);

        if ($organization === null || ! $organization->netsuiteEnabled()) {
            return $result->notAllowedFailure('premium_integration_missing');
        }

        $errors = $this->validateParams($params);
        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $integration = new NetsuiteIntegration([
            // Rails STI: the type column carries the Rails class name.
            'type' => NetsuiteIntegration::NETSUITE_TYPE,
            'organization_id' => $params['organization_id'],
            'name' => $params['name'] ?? null,
            'code' => $params['code'] ?? null,
            // Rails stores the secrets accessors into the encrypted secrets
            // column; the port keeps the plaintext JSON (see Integration).
            'secrets' => json_encode([
                'connection_id' => $params['connection_id'] ?? null,
                'client_secret' => $params['client_secret'] ?? null,
                'token_secret' => $params['token_secret'] ?? null,
            ]),
            'settings' => [
                'client_id' => $params['client_id'] ?? null,
                'script_endpoint_url' => $params['script_endpoint_url'] ?? null,
                'token_id' => $params['token_id'] ?? null,
                'sync_credit_notes' => $this->castBoolean($params['sync_credit_notes'] ?? null),
                'sync_invoices' => $this->castBoolean($params['sync_invoices'] ?? null),
                'sync_payments' => $this->castBoolean($params['sync_payments'] ?? null),
            ],
        ]);

        // Rails: the account_id= override normalizes into settings.
        $integration->setAccountId($params['account_id'] ?? null);

        $integration->save();

        dispatch(new \App\Jobs\Integrations\Aggregator\SendRestletEndpointJob($integration));

        // Rails: PerformSyncJob.set(wait: 2.seconds).perform_later(integration:, sync_items: false).
        dispatch(new \App\Jobs\Integrations\Aggregator\PerformSyncJob($integration, false))->delay(now()->addSeconds(2));

        $result->integration = $integration;

        return $result;
    }

    /** Rails: ActiveModel::Type::Boolean.new.cast. */
    private function castBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Rails: the model presence validations on the secrets/settings
     * accessors + name, and the code uniqueness scoped to the organization.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, list<string>>
     */
    private function validateParams(array $params): array
    {
        $errors = [];

        foreach (['name', 'connection_id', 'client_id', 'client_secret', 'account_id', 'script_endpoint_url'] as $field) {
            if (($params[$field] ?? null) === null || $params[$field] === '') {
                $errors[$field] = ['value_is_mandatory'];
            }
        }

        if (
            ($params['code'] ?? null) !== null
            && NetsuiteIntegration::query()
                ->where('organization_id', $params['organization_id'])
                ->where('code', $params['code'])
                ->exists()
        ) {
            $errors['code'] = ['value_already_exists'];
        }

        return $errors;
    }
}
