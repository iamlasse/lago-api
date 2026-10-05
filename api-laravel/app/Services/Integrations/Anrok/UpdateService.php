<?php

declare(strict_types=1);

namespace App\Services\Integrations\Anrok;

use App\Services\BaseResult;
use App\Models\Integrations\AnrokIntegration;

/**
 * Port of Rails' Integrations::Anrok::UpdateService
 * (app/services/integrations/anrok/update_service.rb) — name/code always in
 * the params key order, api_key re-stored into secrets.
 */
class UpdateService extends \App\Services\BaseService
{
    public function __construct(
        public readonly ?AnrokIntegration $integration,
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

        if (! $this->premium()) {
            return $result->forbiddenFailure();
        }

        $integration = $this->integration;

        if (array_key_exists('name', $this->params)) {
            $integration->name = $this->params['name'];
        }

        if (array_key_exists('code', $this->params)) {
            $integration->code = $this->params['code'];
        }

        if (array_key_exists('api_key', $this->params)) {
            $secrets = json_decode((string) ($integration->secrets ?? '{}'), true) ?: [];
            $secrets['api_key'] = $this->params['api_key'];
            $integration->secrets = json_encode($secrets);
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
    private function validateParams(AnrokIntegration $integration): array
    {
        $errors = [];

        if (($integration->name ?? '') === '') {
            $errors['name'] = ['value_is_mandatory'];
        }

        $codeExists = AnrokIntegration::query()
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
