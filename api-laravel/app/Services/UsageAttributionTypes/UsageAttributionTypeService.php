<?php

declare(strict_types=1);

namespace App\Services\UsageAttributionTypes;

use Illuminate\Support\Facades\DB;
use App\Models\UsageAttributionType;

/**
 * The model-validation legs Rails' UsageAttributionType runs through
 * ActiveModel::Validations on save (code presence + kept-scope uniqueness,
 * name length, attribution_keys presence/length/uniqueness, parent role /
 * organization / cycle checks) — factored here because the frozen-schema
 * model carries no validation hooks.
 */
final class UsageAttributionTypeService
{
    /**
     * Rails: normalizes :attribution_keys — Array(keys).filter_map
     * { to_s.strip.presence }.uniq.
     *
     * @return list<string>
     */
    public static function normalizeAttributionKeys(mixed $keys): array
    {
        $normalized = [];

        foreach ((array) $keys as $key) {
            $trimmed = mb_trim((string) $key);

            if ($trimmed !== '' && ! in_array($trimmed, $normalized, true)) {
                $normalized[] = $trimmed;
            }
        }

        return $normalized;
    }

    /** Rails: params[:code]&.strip. */
    public static function normalizeCode(mixed $code): ?string
    {
        return $code === null ? null : mb_trim((string) $code);
    }

    /** Rails: enum :role — the pg enum index ('hierarchical' / 'flat'). */
    public static function roleIndex(mixed $role): int
    {
        $index = is_int($role) ? $role : array_search((string) $role, UsageAttributionType::ROLES, true);

        return $index === false ? 0 : (int) $index;
    }

    /**
     * Rails: the model validations, in order. Returns the field => [codes]
     * errors hash, or null when everything passes.
     *
     * @return array<string, list<string>>|null
     */
    public static function validate(UsageAttributionType $type): ?array
    {
        $errors = [];

        $code = $type->code;

        if ($code === null || $code === '') {
            $errors['code'] = ["can't be blank"];
        } elseif (mb_strlen($code) > 255) {
            $errors['code'] = ['is too long'];
        } elseif (self::codeTaken($type)) {
            $errors['code'] = ['has already been taken'];
        }

        if (($type->name ?? null) !== null && mb_strlen((string) $type->name) > 255) {
            $errors['name'] = ['is too long'];
        }

        $keys = (array) ($type->attribution_keys ?? []);

        if ($keys === []) {
            $errors['attribution_keys'] = ["can't be blank"];
        } else {
            if (count($keys) > UsageAttributionType::MAX_ATTRIBUTION_KEYS) {
                $errors['attribution_keys'] = ['is too long'];
            } elseif (collect($keys)->contains(fn (string $key): bool => mb_strlen($key) > 255)) {
                $errors['attribution_keys'] = ['is too long'];
            } elseif ($type->claimedByAnotherType()) {
                $errors['attribution_keys'] = ['has already been taken'];
            }
        }

        if (($parentError = self::parentError($type)) !== null) {
            $errors['parent_id'] = [$parentError];
        }

        return $errors === [] ? null : $errors;
    }

    /** Rails: uniqueness scoped to the organization among kept types. */
    private static function codeTaken(UsageAttributionType $type): bool
    {
        $query = DB::table('usage_attribution_types')
            ->where('organization_id', $type->organization_id)
            ->where('code', $type->code)
            ->whereNull('deleted_at');

        if ($type->exists) {
            $query->where('id', '!=', $type->id);
        }

        return $query->exists();
    }

    /**
     * Rails: validate_parent — a parent is forbidden for a flat type, must
     * be hierarchical, belong to the same organization and not form a cycle.
     */
    private static function parentError(UsageAttributionType $type): ?string
    {
        $parent = $type->parent_id === null ? null : UsageAttributionType::withTrashed()->find($type->parent_id);

        if ($parent === null) {
            return null;
        }

        if ($type->flat()) {
            return 'forbidden_for_flat_role';
        }

        if (! $parent->hierarchical()) {
            return 'must_be_hierarchical';
        }

        if ($parent->organization_id !== $type->organization_id) {
            return 'must_belong_to_same_organization';
        }

        if (self::cyclesThrough($type, $parent)) {
            return 'cannot_form_a_cycle';
        }

        return null;
    }

    /** Rails: cycle_through? — walk up the parent chain looking for self. */
    private static function cyclesThrough(UsageAttributionType $type, UsageAttributionType $start): bool
    {
        $visited = [];
        $node = $start;

        while ($node !== null && ! in_array($node->id, $visited, true)) {
            if ($node->id === $type->id) {
                return true;
            }

            $visited[] = $node->id;

            $node = UsageAttributionType::withTrashed()->find($node->parent_id);
        }

        return false;
    }
}
