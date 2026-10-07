<?php

declare(strict_types=1);

namespace App\Services\Roles;

use App\Models\Role;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' Roles::CreateService
 * (app/services/roles/create_service.rb) + the Role model's create
 * validations (the frozen Role model carries no validation hooks).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): Utils::SecurityLog.produce("role.created").
 */
class CreateService extends BaseService
{
    private const array RESERVED_CODES = ['admin', 'finance', 'manager'];

    public function __construct(
        private readonly Organization $organization,
        private readonly ?string $code,
        private readonly ?string $name,
        private readonly ?string $description,
        /** @var list<string>|null */
        private readonly ?array $permissions,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('role');

        // Rails: return result.forbidden_failure! unless License.premium?
        if (! $this->premium()) {
            return $result->forbiddenFailure();
        }

        // Rails: forbidden unless organization.custom_roles_enabled?
        // (`License.premium? && premium_integrations.include?("custom_roles")`
        // — the license half was checked above; the Organization model is
        // read-only in this slice, so the flag lookup stays inline).
        if (! in_array('custom_roles', (array) ($this->organization->premium_integrations ?? []), true)) {
            return $result->forbiddenFailure('premium_integration_missing');
        }

        $errors = $this->validate();

        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $role = new Role([
            'organization_id' => $this->organization->id,
            'code' => $this->code,
            'name' => $this->normalized_name(),
            'description' => $this->description,
            'permissions' => $this->permissions,
            // Rails: the column default (false) — spelled out so the
            // in-memory model serializes non-null.
            'admin' => false,
        ]);

        $role->save();

        $result->role = $role;

        return $result;
    }

    /**
     * Rails Role validations (scoped `if: organization_id && deleted_at.blank?`).
     *
     * @return array<string, list<string>>
     */
    private function validate(): array
    {
        $errors = [];

        $code = $this->code ?? '';
        $name = $this->name ?? '';

        if ($code === '') {
            $errors['code'] = ["can't be blank"];
        } else {
            if (mb_strlen($code) > 100) {
                $errors['code'] = ['is too long'];
            } elseif (preg_match('/\A[a-z0-9_]*\z/', $code) !== 1) {
                $errors['code'] = ['is invalid'];
            } elseif (in_array($code, self::RESERVED_CODES, true)) {
                // Rails: errors.add(:code, :taken) — message "has already
                // been taken".
                $errors['code'] = ['has already been taken'];
            } elseif (
                Role::query()
                    ->where('organization_id', $this->organization->id)
                    ->where('code', $code)
                    ->exists()
            ) {
                $errors['code'] = ['has already been taken'];
            }
        }

        if ($name === '') {
            $errors['name'] = ["can't be blank"];
        } elseif (mb_strlen($name) > 100) {
            $errors['name'] = ['is too long'];
        }

        if ($this->description !== null && mb_strlen($this->description) > 255) {
            $errors['description'] = ['is too long'];
        }

        if ($this->permissions === null || $this->permissions === []) {
            $errors['permissions'] = ["can't be blank"];
        }

        return $errors;
    }

    /** Rails: normalize_name — strip + collapse internal whitespace. */
    private function normalized_name(): ?string
    {
        $name = $this->name;

        if ($name === null) {
            return null;
        }

        return mb_trim((string) preg_replace('/\s+/', ' ', $name));
    }
}
