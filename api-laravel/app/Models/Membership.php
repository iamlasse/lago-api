<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Frozen-schema model for `memberships`. Refined with the Rails Membership
 * model's relations, status enum and domain methods.
 */
#[Fillable([
    'organization_id',
    'user_id',
    'status',
    'revoked_at',
])]
#[Table(name: 'memberships')]
class Membership extends BaseModel
{
    use HasFactory;

    // -- Relationships ----------------------------------------------------------

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function membershipRoles(): HasMany
    {
        return $this->hasMany(MembershipRole::class);
    }

    /**
     * Rails: `has_many :roles, through: :membership_roles`. Both
     * membership_role and role carry Rails' `default_scope -> { kept }` —
     * Laravel's hasManyThrough applies the far model's SoftDeletes scope but
     * not the pivot's, so the kept-filter is spelled out.
     */
    public function roles(): HasManyThrough
    {
        return $this->hasManyThrough(Role::class, MembershipRole::class, 'membership_id', 'id', 'id', 'role_id')
            ->whereNull('membership_roles.deleted_at');
    }

    // -- Domain methods ---------------------------------------------------------

    /**
     * Port of `permissions_hash` — the all-false base map merged with each of
     * the membership's roles' granted permissions (`h[key] ||= val`, so only
     * true values propagate; "admin" roles grant everything).
     *
     * @return array<string, bool>
     */
    public function permissionsHash(): array
    {
        $hash = Permission::permissionsHash();

        foreach ($this->roles()->get() as $role) {
            foreach ($role->permissionsHash() as $permission => $granted) {
                // Ruby's `h[key] ||= val` also overwrites false, not just nil.
                $hash[$permission] = $hash[$permission] || $granted;
            }
        }

        return $hash;
    }

    /**
     * Rails: `mark_as_revoked!` — stamps revoked_at once and flips the
     * status enum to :revoked.
     */
    public function markAsRevoked(?CarbonInterface $timestamp = null): static
    {
        $this->revoked_at ??= $timestamp ?? now();
        $this->status = MembershipStatus::Revoked;
        $this->save();

        return $this;
    }

    /** Rails: `scope :active` equivalent as a relation helper. */
    #[Scope]
    protected function active($query)
    {
        return $query->where('status', MembershipStatus::Active->value);
    }

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'revoked_at' => 'datetime',
        ];
    }
}
