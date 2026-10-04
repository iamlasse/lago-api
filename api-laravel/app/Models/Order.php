<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\HasUuid;
use App\Enums\OrderExecutionMode;
use App\Models\Concerns\Sequenced;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' Order (app/models/order.rb).
 *
 * NOTE on the domain (differs from the older Lago releases): orders are the
 * executable side of a signed ORDER FORM. There is no REST/GraphQL order
 * creation — an order row is created when its order form is signed (the
 * order-forms slice), and execution is dispatched per the quote's order type
 * (one_off / subscription_creation / subscription_amendment).
 *
 * There is NO payment_status / dispute state on orders in the Rails source —
 * the order lifecycle is created → executed|failed only.
 *
 * Every order type writes the same execution_record keys, whether or not it
 * can create such a record, so a reader never has to tell a missing key from
 * an empty one. Records written before a key existed are merged onto these
 * defaults when read (V1\OrderSerializer / Types\Order).
 */
#[Fillable([
    'organization_id',
    'customer_id',
    'order_form_id',
    'number',
    'sequential_id',
    'status',
    'execution_mode',
    'execute_at',
    'executed_at',
    'execution_record',
])]
#[Table(name: 'orders')]
class Order extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use HasUuid;
    use Sequenced;

    /** Rails: STATUSES. */
    public const STATUSES = [
        'created' => 'created',
        'executed' => 'executed',
        'failed' => 'failed',
    ];

    /** Rails: EXECUTION_MODES. */
    public const EXECUTION_MODES = [
        'execute_in_lago' => 'execute_in_lago',
        'order_only' => 'order_only',
    ];

    /** Rails: EXECUTION_RECORD_DEFAULTS. */
    public const EXECUTION_RECORD_DEFAULTS = [
        'executed_at' => null,
        'execution_mode' => null,
        'invoice_id' => null,
        'subscription_ids' => [],
        'terminated_subscription_ids' => [],
        'applied_coupon_ids' => [],
        'wallet_ids' => [],
        'errors' => [],
    ];

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :customer`. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Rails: `belongs_to :order_form`. */
    public function orderForm(): BelongsTo
    {
        return $this->belongsTo(OrderForm::class);
    }

    /**
     * Rails: `has_one :quote_version, through: :order_form` (read helpers —
     * the quotes/order-forms slice owns the relation graph; nothing needs a
     * preloadable relation yet).
     */
    public function quoteVersion(): ?QuoteVersion
    {
        return $this->orderForm?->quoteVersion;
    }

    /** Rails: `has_one :quote, through: :quote_version`. */
    public function quote(): ?Quote
    {
        return $this->quoteVersion()?->quote;
    }

    // -- Delegates ------------------------------------------------------------

    /** Rails: `delegate :order_type, to: :quote` (the quotes.quote_order_type). */
    public function orderType(): ?string
    {
        return $this->quote()?->order_type;
    }

    /** Rails: `delegate :currency, to: :quote_version`. */
    public function currency(): ?string
    {
        return $this->quoteVersion()?->currency;
    }

    /**
     * Rails: `billing_snapshot` — TODO(port): the Rails comment reads
     * "migrate this to a real column when billing items validation is
     * ready"; the snapshot stays the quote version's billing_items JSONB.
     *
     * @return array<string, mixed>|null
     */
    public function billingSnapshot(): ?array
    {
        return $this->quoteVersion()?->billing_items;
    }

    // -- State ----------------------------------------------------------------

    public function isExecuted(): bool
    {
        return $this->status === OrderStatus::Executed;
    }

    public function isCreated(): bool
    {
        return $this->status === OrderStatus::Created;
    }

    public function isFailed(): bool
    {
        return $this->status === OrderStatus::Failed;
    }

    /**
     * Rails scope `executable` — created orders whose execute_at has come
     * due (the clock slice draws this scope).
     */
    public function scopeExecutable(Builder $query): Builder
    {
        return $query
            ->where('status', OrderStatus::Created->value)
            ->whereNotNull('execute_at')
            ->where('execute_at', '<=', now());
    }

    // -- Validations ----------------------------------------------------------

    /**
     * Port of the Rails validations — the status enum inclusion
     * (`enum :status, validate: true`), the execution_mode inclusion
     * (`enum :execution_mode, validate: {allow_nil: true}`) and the
     * execution_mode presence when the order is executed or scheduled
     * (`validates :execution_mode, presence: true, if: {executed? ||
     * execute_at.present?}`).
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        if ($this->status !== null && ! $this->status instanceof OrderStatus) {
            $errors['status'] = ['value_is_invalid'];
        }

        if ($this->execution_mode !== null
            && ! $this->execution_mode instanceof OrderExecutionMode) {
            $errors['execution_mode'] = ['value_is_invalid'];
        }

        if (($this->status === OrderStatus::Executed || $this->execute_at !== null)
            && ($this->execution_mode ?? '') === '') {
            $errors['execution_mode'] = ['value_is_mandatory'];
        }

        return $errors;
    }

    /**
     * Rails: `before_save :ensure_number` — registered AFTER the Sequenced
     * trait's saving hook (parent::boot() boots the traits first), so the
     * sequential_id the number format needs is already assigned.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $order): void {
            $order->ensureNumber();
        });
    }

    // -- Sequenced ------------------------------------------------------------

    /** Rails: `sequenced scope: ->(order) { order.organization.orders }`. */
    protected function sequenceScope(): Builder
    {
        return static::query()->where('organization_id', $this->organization_id);
    }

    /** Rails: `sequenced lock_key: ->(order) { order.organization_id }`. */
    protected function sequencedLockKey(): string
    {
        return (string) $this->organization_id;
    }

    // -- Number ---------------------------------------------------------------

    /** Rails: `ensure_number` — "OR-YYYY-0000" from the sequential id. */
    protected function ensureNumber(): void
    {
        if (($this->number ?? '') !== '') {
            return;
        }

        if ($this->sequential_id === null) {
            return;
        }

        $time = $this->created_at ?? now();

        $this->number = 'OR-'.$time->format('Y').'-'.mb_str_pad((string) $this->sequential_id, 4, '0', STR_PAD_LEFT);
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'execution_mode' => OrderExecutionMode::class,
            'execute_at' => 'datetime',
            'executed_at' => 'datetime',
            'execution_record' => 'array',
        ];
    }
}
