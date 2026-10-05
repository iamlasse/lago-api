<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InviteStatus;
use Carbon\CarbonInterface;
use App\Models\Casts\PostgresArray;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' Invite (app/models/invite.rb) — minimal, covering the SSO
 * accept-invite flows. The invites create/revoke/REST surface belongs to its
 * own slice.
 *
 * TODO(port): Rails `normalizes :email` with EmailSanitizer and the
 * `validates :email, email: true` / token uniqueness validations live with
 * the invites create slice; PaperTrailTraceable (audit `versions` rows) is
 * not ported.
 */
#[Fillable([
    'organization_id',
    'membership_id',
    'email',
    'token',
    'status',
    'accepted_at',
    'revoked_at',
    'roles',
])]
#[Table(name: 'invites')]
class Invite extends BaseModel
{
    use HasFactory;

    // -- Relationships ----------------------------------------------------------

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Rails: belongs_to :recipient, class_name: "Membership", foreign_key: :membership_id. */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'membership_id');
    }

    // -- Domain methods ---------------------------------------------------------

    /**
     * Rails: `mark_as_revoked!` — stamps revoked_at once and flips the status
     * enum to :revoked.
     */
    public function markAsRevoked(?CarbonInterface $timestamp = null): static
    {
        $this->revoked_at ??= $timestamp ?? now();
        $this->status = InviteStatus::Revoked;
        $this->save();

        return $this;
    }

    /** Rails: `mark_as_accepted!`. */
    public function markAsAccepted(?CarbonInterface $timestamp = null): static
    {
        $this->accepted_at ??= $timestamp ?? now();
        $this->status = InviteStatus::Accepted;
        $this->save();

        return $this;
    }

    /** Rails: `scope :pending` via the status enum (Invite.pending). */
    public function scopePending($query)
    {
        return $query->where('status', InviteStatus::Pending);
    }

    protected function casts(): array
    {
        return [
            'status' => InviteStatus::class,
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            // Frozen column is varchar[] — the JSON 'array' cast writes a
            // malformed array literal.
            'roles' => PostgresArray::class,
        ];
    }
}
