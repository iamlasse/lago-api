<?php

declare(strict_types=1);

namespace App\Services\Entitlements\Concerns;

use App\Models\Privilege;
use App\Services\BaseResult;
use App\Services\Failures\ValidationFailure;

/**
 * Port of Rails' Entitlement::Concerns::CreateOrUpdateConcern
 * (app/services/entitlement/concerns/create_or_update_concern.rb) —
 * `validate_value` type-checks a privilege value against the privilege's
 * value_type (per the PG enum entitlement_privilege_value_types) and
 * raises the BaseService::ValidationFailure envelope.
 */
trait ValidatesPrivilegeValue
{
    /**
     * Rails: `validate_value` — nil passes through; select values must be
     * one of the config's select_options; booleans accept true/false and
     * their string forms; integers accept ints and numeric strings;
     * strings must be strings.
     *
     * @return mixed the (validated, uncast) value
     */
    protected function validatePrivilegeValue(mixed $value, Privilege $privilege, BaseResult $result): mixed
    {
        if ($value === null) {
            return $value;
        }

        $valueType = $privilege->value_type;

        if ($valueType === 'select') {
            $options = is_array($privilege->config) ? ($privilege->config['select_options'] ?? []) : [];

            if (in_array($value, $options, true)) {
                return $value;
            }

            throw new ValidationFailure($result, [
                $privilege->code.'_privilege_value' => ['value_not_in_select_options'],
            ]);
        }

        if ($valueType === 'boolean' && in_array($value, [true, false, 'true', 'false'], true)) {
            return $value;
        }

        if ($valueType === 'integer') {
            if (is_int($value)) {
                return $value;
            }

            if (is_string($value) && (string) (int) $value === $value) {
                return $value;
            }
        }

        if ($valueType === 'string' && is_string($value)) {
            return $value;
        }

        throw new ValidationFailure($result, [
            $privilege->code.'_privilege_value' => ['value_is_invalid'],
        ]);
    }
}
