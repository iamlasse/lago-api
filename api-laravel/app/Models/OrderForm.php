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
 * Minimal read-model scaffold of Rails' OrderForm (app/models/order_form.rb)
 * — only what the ORDERS slice needs (the order → quote_version → quote
 * walk, and the number format the read surface emits).
 *
 * TODO(port): the full order-forms slice (status enum port, signing /
 * voiding / expiry transitions, signed documents) — this class stays
 * read-only until then.
 */
#[Fillable([
    'organization_id',
    'customer_id',
    'quote_version_id',
    'number',
    'sequential_id',
    'status',
    'expires_at',
    'signed_at',
    'voided_at',
])]
#[Table(name: 'order_forms')]
class OrderForm extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use HasUuid;
    use Sequenced;

    /** Rails: STATUSES (order_form.rb). */
    public const STATUSES = ['generated', 'signed', 'expired', 'voided'];

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :customer`. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Rails: `belongs_to :quote_version`. */
    public function quoteVersion(): BelongsTo
    {
        return $this->belongsTo(QuoteVersion::class);
    }

    /** Rails: `has_one :quote, through: :quote_version`. */
    public function quote(): ?Quote
    {
        return $this->quoteVersion?->quote;
    }

    /** Rails: `has_one :order`. */
    public function order(): ?Order
    {
        return Order::query()->where('order_form_id', $this->getKey())->first();
    }

    /**
     * Rails: `before_save :ensure_number` — registered after the Sequenced
     * trait's saving hook, so the sequential_id is already assigned.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $orderForm): void {
            $orderForm->ensureNumber();
        });
    }

    /** Rails: `ensure_number` — "OF-YYYY-0000" from the sequential id. */
    protected function ensureNumber(): void
    {
        if (($this->number ?? '') !== '' || $this->sequential_id === null) {
            return;
        }

        $time = $this->created_at ?? now();

        $this->number = 'OF-'.$time->format('Y').'-'.mb_str_pad((string) $this->sequential_id, 4, '0', STR_PAD_LEFT);
    }

    // -- Sequenced ------------------------------------------------------------

    /** Rails: `sequenced scope: ->(order_form) { order_form.organization.order_forms }`. */
    protected function sequenceScope(): Builder
    {
        return static::query()->where('organization_id', $this->organization_id);
    }

    /** Rails: `sequenced lock_key: ->(order_form) { order_form.organization_id }`. */
    protected function sequencedLockKey(): string
    {
        return (string) $this->organization_id;
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'signed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
