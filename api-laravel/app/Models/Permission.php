<?php

declare(strict_types=1);

namespace App\Models;

use Symfony\Component\Yaml\Yaml;

/**
 * Port of Rails' Permission module (app/models/permission.rb) — the
 * permission tree from config/permissions.yml flattened to dotted keys
 * ("addons:view"), each holding the list of predefined role names granted.
 * A role of "admin" grants everything.
 */
final class Permission
{
    /** Rails: DATA — the flattened, frozen config/permissions.yml. */
    private static ?array $data = null;

    /**
     * Rails: `Permission.permissions_hash(role = nil)` — every permission
     * mapped to a boolean: true for "admin" or when the role appears in the
     * permission's granted-role list. With no role, everything is false.
     *
     * @return array<string, bool>
     */
    public static function permissionsHash(?string $role = null): array
    {
        $role = mb_strtolower((string) $role);

        $hash = [];

        foreach (self::data() as $permission => $grantedRoles) {
            $hash[$permission] = $role === 'admin' || in_array($role, $grantedRoles, true);
        }

        return $hash;
    }

    /**
     * Rails: `yaml_to_hash` + DottedHash — nested config/permissions.yml
     * flattened with ":" separators, leaf values kept as role-name lists.
     *
     * @return array<string, list<string>>
     */
    private static function data(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $flat = [];

        $walk = function (array $node, string $prefix) use (&$flat, &$walk): void {
            foreach ($node as $key => $value) {
                $dotted = $prefix === '' ? (string) $key : $prefix.':'.$key;

                if (is_array($value) && ! array_is_list($value)) {
                    $walk($value, $dotted);
                } else {
                    $flat[$dotted] = array_values((array) $value);
                }
            }
        };

        $walk((array) Yaml::parseFile(config_path('permissions.yml')) ?? [], '');

        return self::$data = $flat;
    }
}
