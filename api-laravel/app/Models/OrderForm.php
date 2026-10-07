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
 * Port of Rails' OrderForm (app/models/order_form.rb).
 *
 * The signable document over an approved quote version: one order form per
 * version (unique quote_version_id), signing it creates the order, voiding
 * or expiring it cascades a void onto the version.
 */
#[Fillable([
    'organization_id',
    'customer_id',
    'quote_version_id',
    'number',
    'sequential_id',
    'status',
    'void_reason',
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

    /** Rails: `enum :status, STATUSES, default: :generated`. */
    public const STATUSES = [
        'generated' => 'generated',
        'signed' => 'signed',
        'expired' => 'expired',
        'voided' => 'voided',
    ];

    /** Rails: `enum :void_reason, VOID_REASONS`. */
    public const VOID_REASONS = [
        'manual' => 'manual',
        'expired' => 'expired',
        'invalid' => 'invalid',
    ];

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

    // -- Status ---------------------------------------------------------------

    /** Rails: `generated?`. */
    public function isGenerated(): bool
    {
        return $this->status === 'generated';
    }

    /** Rails: `signed?`. */
    public function isSigned(): bool
    {
        return $this->status === 'signed';
    }

    /** Rails: `expired?`. */
    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    /** Rails: `voided?`. */
    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }

    // -- Scopes ---------------------------------------------------------------

    /**
     * Rails: `scope :expirable` — generated forms whose expiry date (in the
     * customer billing entity's timezone) has come. The clock-driven
     * OrderForms::ExpireJob selects with it.
     */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function expirable(Builder $query): Builder
    {
        return $query
            ->where('status', 'generated')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    // -- Number ---------------------------------------------------------------

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
