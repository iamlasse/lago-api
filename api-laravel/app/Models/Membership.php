<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `memberships`. Refined with the Rails Membership
 * model's relations, status enum and domain methods.
 */
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'organization_id',
    'user_id',
    'status',
    'revoked_at',
])]
#[\Illuminate\Database\Eloquent\Attributes\Table(name: 'memberships')]
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

    // -- Domain methods ---------------------------------------------------------

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
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
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
