<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Models\Concerns\Sequenced;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Minimal read-model scaffold of Rails' Quote (app/models/quote.rb) — only
 * what the ORDERS slice needs (the order_type delegate and the number).
 *
 * TODO(port): the full quotes slice (versions workflow, approval, images,
 * owners) — this class stays read-only until then.
 */
#[Fillable([
    'organization_id',
    'customer_id',
    'subscription_id',
    'number',
    'sequential_id',
    'order_type',
])]
#[Table(name: 'quotes')]
class Quote extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use HasUuid;
    use Sequenced;

    /** Rails: `enum :order_type, ORDER_TYPES` (quotes.order_type values). */
    public const ORDER_TYPES = [
        'subscription_creation' => 'subscription_creation',
        'subscription_amendment' => 'subscription_amendment',
        'one_off' => 'one_off',
    ];

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :customer`. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Rails: `belongs_to :subscription` (optional). */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** Rails: `has_many :quote_versions`. */
    public function quoteVersions(): HasMany
    {
        return $this->hasMany(QuoteVersion::class);
    }

    /**
     * Rails: `before_save :ensure_number` — registered after the Sequenced
     * trait's saving hook, so the sequential_id is already assigned.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $quote): void {
            $quote->ensureNumber();
        });
    }

    /** Rails: `ensure_number` — "QT-YYYY-0000" from the sequential id. */
    protected function ensureNumber(): void
    {
        if (($this->number ?? '') !== '' || $this->sequential_id === null) {
            return;
        }

        $time = $this->created_at ?? now();

        $this->number = 'QT-'.$time->format('Y').'-'.mb_str_pad((string) $this->sequential_id, 4, '0', STR_PAD_LEFT);
    }

    // -- Sequenced ------------------------------------------------------------

    /** Rails: sequenced over the organization's quotes. */
    protected function sequenceScope(): Builder
    {
        return static::query()->where('organization_id', $this->organization_id);
    }

    /** Rails: `sequenced lock_key: ->(quote) { quote.organization_id }`. */
    protected function sequencedLockKey(): string
    {
        return (string) $this->organization_id;
    }
}
