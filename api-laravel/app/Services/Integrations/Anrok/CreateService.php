<?php

declare(strict_types=1);

namespace App\Services\Integrations\Anrok;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Models\Integrations\AnrokIntegration;

/**
 * Port of Rails' Integrations::Anrok::CreateService
 * (app/services/integrations/anrok/create_service.rb) — Anrok is a
 * NON-premium integration (Organization NON_PREMIUM_INTEGRATIONS), so the
 * only gate is the license.
 */
class CreateService extends \App\Services\BaseService
{
    public function __construct(
        ?object $user = null,
        public readonly ?string $name = null,
        public readonly ?string $code = null,
        public readonly ?string $organization_id = null,
        public readonly ?string $connection_id = null,
        public readonly ?string $api_key = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('integration');

        if (! $this->premium()) {
            return $result->forbiddenFailure();
        }

        $errors = $this->validateParams();
        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $integration = new AnrokIntegration([
            // Rails STI: the type column carries the Rails class name.
            'type' => AnrokIntegration::ANROK_TYPE,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'code' => $this->code,
            // Rails stores the secrets accessors into the encrypted secrets
            // column; the port keeps the plaintext JSON (see Integration).
            'secrets' => json_encode([
                'connection_id' => $this->connection_id,
                'api_key' => $this->api_key,
            ]),
        ]);

        $integration->save();

        $result->integration = $integration;

        return $result;
    }

    /**
     * Rails: the model presence validations on the secrets accessors + name,
     * and the code uniqueness scoped to the organization.
     *
     * @return array<string, list<string>>
     */
    private function validateParams(): array
    {
        $errors = [];

        foreach (['name', 'code', 'connection_id', 'api_key'] as $field) {
            if ($this->{$field} === null || $this->{$field} === '') {
                $errors[$field] = ['value_is_mandatory'];
            }
        }

        if (
            $this->code !== null
            && AnrokIntegration::query()
                ->where('organization_id', $this->organization_id)
                ->where('code', $this->code)
                ->exists()
        ) {
            $errors['code'] = ['value_already_exists'];
        }

        return $errors;
    }
}
