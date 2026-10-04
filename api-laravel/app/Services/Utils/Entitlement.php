<?php

declare(strict_types=1);

namespace App\Services\Utils;

/**
 * Port of Rails' Utils::Entitlement (app/services/utils/entitlement.rb) —
 * the privilege-param helpers shared by the update services, the
 * serializers and the GraphQL mutations.
 */
class Entitlement
{
    /**
     * Rails: `privilege_code_is_duplicated?` — true when any privilege
     * param's code repeats.
     *
     * @param  mixed  $privilegesParams  list<array<string, mixed>>|null
     */
    public static function privilegeCodeIsDuplicated(mixed $privilegesParams): bool
    {
        if ($privilegesParams === null || $privilegesParams === []) {
            return false;
        }

        $seen = [];

        foreach ($privilegesParams as $privilegeParams) {
            $code = $privilegeParams['code'] ?? null;

            if (in_array($code, $seen, true)) {
                return true;
            }

            $seen[] = $code;
        }

        return false;
    }

    /**
     * Rails: `cast_value` — string column values rendered in their value
     * type for the API payloads (integers and booleans are cast; everything
     * else is passed through).
     */
    public static function castValue(mixed $value, ?string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($type === 'integer') {
            return (int) $value;
        }

        if ($type === 'boolean') {
            // Rails: ActiveModel::Type::Boolean — the legacy PG "t"/"f"
            // strings cast; anything else is nil.
            $truthy = [true, 1, '1', 't', 'T', 'true', 'TRUE', 'on', 'ON'];
            $falsy = [false, 0, '0', 'f', 'F', 'false', 'FALSE', 'off', 'OFF'];

            if (in_array($value, $truthy, true)) {
                return true;
            }

            if (in_array($value, $falsy, true)) {
                return false;
            }

            return null;
        }

        return $value;
    }

    /** Rails: `same_value?` — equality after the value-type cast. */
    public static function sameValue(?string $type, mixed $value1, mixed $value2): bool
    {
        return self::castValue($value1, $type) === self::castValue($value2, $type);
    }

    /**
     * Rails: `convert_gql_input_to_params` — the GraphQL entitlements input
     * (list of {featureCode, privileges: [{privilegeCode, value}]}) mapped
     * onto the services' `feature_code => {privilege_code => value}` hash.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function convertGqlInputToParams(mixed $entitlements): array
    {
        $params = [];

        foreach ((array) $entitlements as $entitlement) {
            $entitlement = (object) $entitlement;

            $privileges = [];

            foreach ((array) ($entitlement->privileges ?? []) as $privilege) {
                $privilege = (object) $privilege;
                $privileges[$privilege->privilege_code] = $privilege->value;
            }

            $params[$entitlement->feature_code] = $privileges;
        }

        return $params;
    }
}
