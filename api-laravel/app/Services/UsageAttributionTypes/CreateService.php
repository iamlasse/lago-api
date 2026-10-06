<?php

declare(strict_types=1);

namespace App\Services\UsageAttributionTypes;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\UsageAttributionType;

/**
 * Port of Rails' UsageAttributionTypes::CreateService — creates an
 * attribution type of the organization (optionally under a parent). The
 * model validations (code presence/uniqueness among kept types, name and
 * attribution_keys lengths, parent role/organization/cycle checks) are
 * ported in UsageAttributionTypeService::validate.
 *
 * TODO(port): none of the Model-level validators raise here beyond the
 * parent lookup failure; the DB constraints are the hard guarantee.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly ?object $organization,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('usage_attribution_type');

        if ($this->organization === null) {
            return $result->notFoundFailure('organization');
        }

        /** @var \App\Models\Organization $organization */
        $organization = $this->organization;

        $parent = null;

        if (($this->params['parent_id'] ?? null) !== null) {
            $parent = $organization->usageAttributionTypes()
                ->where('id', $this->params['parent_id'])
                ->first();

            if ($parent === null) {
                return $result->notFoundFailure('parent_usage_attribution_type');
            }
        }

        $type = new UsageAttributionType([
            'organization_id' => $organization->id,
            'code' => UsageAttributionTypeService::normalizeCode($this->params['code'] ?? null),
            'name' => $this->params['name'] ?? null,
            'description' => $this->params['description'] ?? null,
            'attribution_keys' => UsageAttributionTypeService::normalizeAttributionKeys($this->params['attribution_keys'] ?? null),
            'role' => UsageAttributionTypeService::roleIndex($this->params['role'] ?? null),
            'parent_id' => $parent?->id,
        ]);

        $errors = UsageAttributionTypeService::validate($type);

        if ($errors !== null) {
            return $result->recordValidationFailure($errors);
        }

        $type->save();

        $result->usage_attribution_type = $type;

        return $result;
    }
}
