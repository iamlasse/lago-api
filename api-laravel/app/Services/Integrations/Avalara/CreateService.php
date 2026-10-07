<?php

declare(strict_types=1);

namespace App\Services\Integrations\Avalara;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Models\Integrations\AvalaraIntegration;
use App\Jobs\Integrations\Avalara\FetchCompanyIdJob;

/**
 * Port of Rails' Integrations::Avalara::CreateService
 * (app/services/integrations/avalara/create_service.rb) — a PREMIUM
 * integration gated on Organization#avalara_enabled?; the Avalara company
 * id is fetched asynchronously right after the row is stored.
 */
class CreateService extends \App\Services\BaseService
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(public readonly array $params = [])
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('integration');

        $organization = Organization::query()->find($this->params['organization_id'] ?? null);

        if ($organization === null || ! $organization->avalaraEnabled()) {
            return $result->notAllowedFailure('premium_integration_missing');
        }

        $errors = $this->validateParams();
        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $integration = new AvalaraIntegration([
            // Rails STI: the type column carries the Rails class name.
            'type' => AvalaraIntegration::AVALARA_TYPE,
            'organization_id' => $organization->id,
            'name' => $this->params['name'],
            'code' => $this->params['code'],
            'settings' => [
                'company_code' => $this->params['company_code'],
                'account_id' => $this->params['account_id'],
            ],
            'secrets' => json_encode([
                'connection_id' => $this->params['connection_id'],
                'license_key' => $this->params['license_key'],
            ]),
        ]);

        $integration->save();

        dispatch(new \App\Jobs\Integrations\Avalara\FetchCompanyIdJob($integration));

        $result->integration = $integration;

        return $result;
    }

    /**
     * Rails: validates :company_code, :connection_id, :account_id,
     * :license_key, presence: true (+ name presence, code uniqueness).
     *
     * @return array<string, list<string>>
     */
    private function validateParams(): array
    {
        $errors = [];

        foreach (['name', 'code', 'company_code', 'connection_id', 'account_id', 'license_key'] as $field) {
            if (($this->params[$field] ?? null) === null || $this->params[$field] === '') {
                $errors[$field] = ['value_is_mandatory'];
            }
        }

        if (
            ($this->params['code'] ?? null) !== null
            && AvalaraIntegration::query()
                ->where('organization_id', $this->params['organization_id'] ?? null)
                ->where('code', $this->params['code'])
                ->exists()
        ) {
            $errors['code'] = ['value_already_exists'];
        }

        return $errors;
    }
}
