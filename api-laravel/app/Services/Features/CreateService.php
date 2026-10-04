<?php

declare(strict_types=1);

namespace App\Services\Features;

use App\Models\Feature;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Utils\Entitlement;
use App\Services\Failures\FailedResult;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Port of Rails' Entitlement::FeatureCreateService
 * (app/services/entitlement/feature_create_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): activity log middleware (activity_loggable "feature.created").
 * - TODO(port): SendWebhookJob.perform_after_commit("feature.created", feature)
 *   — the webhook emission point is marked below.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly ?Organization $organization,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('feature');
        $organization = $this->organization;
        $params = $this->params;

        if ($organization === null) {
            return $result->notFoundFailure('organization');
        }

        if (Entitlement::privilegeCodeIsDuplicated($params['privileges'] ?? null)) {
            return $result->singleValidationFailure('value_is_duplicated', 'privilege.code');
        }

        try {
            DB::transaction(function () use ($organization, $params, $result): void {
                $feature = new Feature([
                    'organization_id' => $organization->id,
                    'code' => isset($params['code']) && is_string($params['code']) ? mb_trim($params['code']) : null,
                    'name' => $params['name'] ?? null,
                    'description' => $params['description'] ?? null,
                ]);

                $errors = $feature->validateAttributes();

                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $feature->save();

                if (! empty($params['privileges'])) {
                    $this->createPrivileges($feature, $params['privileges'], $result);
                }

                $result->feature = $feature;
            });

            // TODO(port): SendWebhookJob.perform_after_commit(
            //   "feature.created", feature) — the emission point.

            return $result;
        } catch (UniqueConstraintViolationException) {
            // Rails: rescue ActiveRecord::RecordNotUnique — the partial unique
            // index (code, organization_id) WHERE deleted_at IS NULL.
            return $result->singleValidationFailure('value_already_exist', 'code');
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /**
     * Rails: `create_privileges` — one saved privilege per param; only the
     * provided keys are assigned (value_type keeps the column default
     * "string", config the `{}` default).
     *
     * @param  list<array<string, mixed>>  $privilegesParams
     */
    private function createPrivileges(Feature $feature, array $privilegesParams, BaseResult $result): void
    {
        foreach ($privilegesParams as $privilegeParams) {
            $privilegeParams = is_array($privilegeParams) ? $privilegeParams : [];

            $privilege = $feature->privileges()->make([
                'organization_id' => $feature->organization_id,
                'code' => isset($privilegeParams['code']) && is_string($privilegeParams['code'])
                    ? mb_trim($privilegeParams['code'])
                    : null,
                'name' => $privilegeParams['name'] ?? null,
            ]);

            if (array_key_exists('value_type', $privilegeParams)) {
                $privilege->value_type = $privilegeParams['value_type'];
            }

            if (array_key_exists('config', $privilegeParams)) {
                $privilege->config = $privilegeParams['config'];
            }

            $errors = $privilege->validateAttributes();

            if ($errors !== []) {
                // Rails prefixes the field names — you can get a "code" error
                // from the feature or the privilege, so the privilege's keys
                // are namespaced.
                $result->validationFailure($this->prefixPrivilegeErrors($errors))->raiseIfError();
            }

            $privilege->save();
        }
    }

    /**
     * Rails: `errors.messages.transform_keys { |key| :"privilege.#{key}" }`.
     *
     * @param  array<string, list<string>>  $errors
     * @return array<string, list<string>>
     */
    private function prefixPrivilegeErrors(array $errors): array
    {
        $prefixed = [];

        foreach ($errors as $field => $codes) {
            $prefixed['privilege.'.$field] = $codes;
        }

        return $prefixed;
    }
}
