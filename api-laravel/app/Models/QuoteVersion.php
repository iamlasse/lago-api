<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Models\Concerns\Sequenced;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Minimal read-model scaffold of Rails' QuoteVersion
 * (app/models/quote_version.rb) — only what the ORDERS slice needs: the
 * billing_items snapshot the order serializer exposes, the currency the
 * order delegates to, and the billing entity the execution bills on.
 *
 * TODO(port): the full quote-versions slice (draft/approved/voided
 * lifecycle, content rendering, mention variables) — this class stays
 * read-only until then.
 */
#[Fillable([
    'organization_id',
    'quote_id',
    'sequential_id',
    'status',
    'approved_at',
    'voided_at',
    'void_reason',
    'billing_items',
    'content',
    'share_token',
    'currency',
    'mention_variables',
    'billing_entity_id',
])]
#[Table(name: 'quote_versions')]
class QuoteVersion extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use HasUuid;
    use Sequenced;

    /** Rails: `enum :status, QUOTE_STATUSES, default: :draft`. */
    public const STATUSES = ['draft', 'approved', 'voided'];

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :quote`. */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /** Rails: `belongs_to :billing_entity` (optional). */
    public function billingEntity(): BelongsTo
    {
        return $this->belongsTo(BillingEntity::class);
    }

    // -- Sequenced ------------------------------------------------------------

    /** Rails: sequenced over the parent quote's versions. */
    protected function sequenceScope(): Builder
    {
        return static::query()->where('quote_id', $this->quote_id);
    }

    protected function casts(): array
    {
        return [
            'billing_items' => 'array',
            'mention_variables' => 'array',
            'approved_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
