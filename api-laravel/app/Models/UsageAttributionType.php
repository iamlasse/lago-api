<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use function in_array;

/**
 * Frozen-schema model for `usage_attribution_types` (Rails'
 * UsageAttributionType) — the account-tree attribution taxonomy (a tree of
 * hierarchical or flat types, each keyed by up to MAX_ATTRIBUTION_KEYS
 * attribution keys).
 *
 * Rails `include Discard::Model` (deleted_at) → SoftDeletes; the default
 * kept scope excludes discarded types (a discarded parent still resolves
 * through parent(), like Rails' `belongs_to :parent, -> { with_discarded }`).
 *
 * The port keeps Rails' uniqueness constraint (organization + code where
 * deleted_at is null — the frozen partial unique index) as a soft check in
 * the create/update services; the DB index is the hard guarantee.
 */
#[Fillable([
    'organization_id',
    'parent_id',
    'code',
    'name',
    'role',
    'attribution_keys',
    'description',
])]
#[Table(name: 'usage_attribution_types')]
class UsageAttributionType extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    /** Rails: UsageAttributionType::ROLES (pg enum order). */
    public const ROLES = ['hierarchical', 'flat'];

    /** Rails: MAX_ATTRIBUTION_KEYS. */
    public const MAX_ATTRIBUTION_KEYS = 4;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Rails: belongs_to :parent, -> { with_discarded }, optional. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id')->withTrashed();
    }

    /** Rails: has_many :children, -> { order(:code) }, foreign_key: :parent_id. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    /** Rails: has_many :usage_attribution_values. */
    public function usageAttributionValues(): HasMany
    {
        return $this->hasMany(UsageAttributionValue::class);
    }

    /** The role's wire name ("hierarchical" / "flat"). */
    public function roleName(): string
    {
        return $this->role;
    }

    public function hierarchical(): bool
    {
        return $this->roleName() === 'hierarchical';
    }

    public function flat(): bool
    {
        return $this->roleName() === 'flat';
    }

    /**
     * Rails: claimed_by_another_type? — another type of the organization
     * already claims one of these attribution keys (with_discarded counts).
     */
    public function claimedByAnotherType(): bool
    {
        $keys = array_values(array_filter((array) $this->attribution_keys));

        if ($keys === []) {
            return false;
        }

        // Rails: attribution_keys && ARRAY[?]::varchar[] — the pg array
        // overlap operator (a discarded type still claims its keys).
        return DB::table('usage_attribution_types')
            ->where('organization_id', $this->organization_id)
            ->where('id', '!=', $this->id)
            ->whereRaw(
                'attribution_keys && ARRAY['.implode(',', array_fill(0, count($keys), '?')).']::varchar[]',
                $keys,
            )
            ->exists();
    }

    /**
     * The `role` column is a native pg enum (usage_attribution_type_role);
     * the model reads/writes its wire name ("hierarchical" / "flat").
     */
    protected function role(): Attribute
    {
        return Attribute::get(
            fn (mixed $value): string => self::ROLES[(int) $value] ?? 'hierarchical',
        )->set(
            // The pg enum binds its wire name, never the position.
            fn (mixed $value): string => match (true) {
                is_int($value) => self::ROLES[$value] ?? 'hierarchical',
                in_array((string) $value, self::ROLES, true) => (string) $value,
                default => (string) $value,
            },
        );
    }

    protected function casts(): array
    {
        return [
            'attribution_keys' => Casts\PostgresArray::class,
        ];
    }
}
