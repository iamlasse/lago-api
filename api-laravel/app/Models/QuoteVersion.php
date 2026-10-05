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
 * Port of Rails' QuoteVersion (app/models/quote_version.rb).
 *
 * A quote's state lives on immutable versions: at most one draft or
 * approved version is active per quote (partial unique index in the frozen
 * schema), voiding frees the slot for the next draft.
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

    /** Rails: `enum :status, STATUSES, default: :draft`. */
    public const STATUSES = [
        'draft' => 'draft',
        'approved' => 'approved',
        'voided' => 'voided',
    ];

    /** Rails: `enum :void_reason, VOID_REASONS`. */
    public const VOID_REASONS = [
        'manual' => 'manual',
        'superseded' => 'superseded',
        'cascade_of_expired' => 'cascade_of_expired',
        'cascade_of_voided' => 'cascade_of_voided',
    ];

    /** Rails: CASCADE_VOID_REASONS — the reasons an approved version may be voided with. */
    public const CASCADE_VOID_REASONS = [
        'cascade_of_expired' => 'cascade_of_expired',
        'cascade_of_voided' => 'cascade_of_voided',
    ];

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :quote`. */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /** Rails: `belongs_to :billing_entity` (optional). */
    public function billingEntityRecord(): BelongsTo
    {
        return $this->belongsTo(BillingEntity::class, 'billing_entity_id');
    }

    /** Rails: `has_one :order_form`. */
    public function orderForm()
    {
        return $this->hasOne(OrderForm::class);
    }

    // -- Status ---------------------------------------------------------------

    /** Rails: `draft?`. */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** Rails: `approved?`. */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /** Rails: `voided?`. */
    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }

    /** Rails: `def version = sequential_id`. */
    public function version(): int
    {
        return (int) $this->sequential_id;
    }

    /**
     * Rails: `delegate :customer, to: :quote`.
     */
    public function customer(): ?Customer
    {
        return $this->quote?->customer;
    }

    /**
     * Rails: `#billing_entity` — the resolved issuer. A blank billing entity
     * means the deal follows whichever entity will bill it: the subscription
     * an amendment restates first, then the customer's own.
     *
     * Every hop is guarded: a version with no quote yet reads as having no
     * entity.
     */
    public function resolvedBillingEntity(): ?BillingEntity
    {
        return $this->billingEntityRecord
            ?? $this->amendedSubscription()?->billingEntity
            ?? $this->quote?->customer?->billingEntity;
    }

    /**
     * Rails: `#applicable_billing_entity_id` — the id form of the same chain.
     */
    public function applicableBillingEntityId(): ?string
    {
        return $this->billing_entity_id
            ?? $this->amendedSubscription()?->applicable_billing_entity_id
            ?? $this->quote?->customer?->billing_entity_id;
    }

    /**
     * Rails: `#amended_subscription` — only an amendment restates a running
     * subscription; any other order type ignores it entirely.
     */
    protected function amendedSubscription(): ?Subscription
    {
        if ($this->quote?->order_type !== Quote::ORDER_TYPES['subscription_amendment']) {
            return null;
        }

        return $this->quote->subscription;
    }

    // -- Sequenced ------------------------------------------------------------

    /** Rails: sequenced over the parent quote's versions. */
    protected function sequenceScope(): Builder
    {
        return static::query()->where('quote_id', $this->quote_id);
    }

    /** Rails: `sequenced lock_key: ->(quote_version) { quote_version.quote_id }`. */
    protected function sequencedLockKey(): string
    {
        return (string) $this->quote_id;
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
