<?php

declare(strict_types=1);

namespace App\Services\Roles;

use App\Models\Role;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' Roles::UpdateService
 * (app/services/roles/update_service.rb) — only name/description/permissions
 * are assignable, present values only (`params.slice(...).compact`).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): Utils::SecurityLog.produce("role.updated").
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?Role $role,
        /** @var array<string, mixed> */
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('role');

        if ($this->role === null) {
            return $result->notFoundFailure('role');
        }

        // Rails: forbidden_failure!(code: "predefined_role") when the role
        // is a predefined one (organization_id nil).
        if ($this->role->organization_id === null) {
            return $result->forbiddenFailure('predefined_role');
        }

        if (array_key_exists('name', $this->params) && $this->params['name'] !== null) {
            $name = (string) $this->params['name'];

            if ($name === '') {
                return $result->recordValidationFailure(['name' => ["can't be blank"]]);
            }

            if (mb_strlen($name) > 100) {
                return $result->recordValidationFailure(['name' => ['is too long']]);
            }

            $this->role->name = mb_trim((string) preg_replace('/\s+/', ' ', $name));
        }

        if (array_key_exists('description', $this->params) && $this->params['description'] !== null) {
            $description = (string) $this->params['description'];

            if (mb_strlen($description) > 255) {
                return $result->recordValidationFailure(['description' => ['is too long']]);
            }

            $this->role->description = $description;
        }

        if (array_key_exists('permissions', $this->params) && $this->params['permissions'] !== null) {
            $permissions = (array) $this->params['permissions'];

            if ($permissions === []) {
                return $result->recordValidationFailure(['permissions' => ["can't be blank"]]);
            }

            $this->role->permissions = $permissions;
        }

        $this->role->save();

        $result->role = $this->role;

        return $result;
    }
}
