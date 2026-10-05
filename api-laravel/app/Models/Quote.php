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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Port of Rails' Quote (app/models/quote.rb).
 *
 * The quote is the deal header: the order type decides what signing it
 * executes, and the number ("QT-YYYY-0000") is what every read surface
 * shows. Everything stateful — draft/approved/voided lifecycle, billing
 * items, content — lives on the versions.
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

    /** Rails: `enum :order_type, ORDER_TYPES`. */
    public const ORDER_TYPES = [
        'subscription_creation' => 'subscription_creation',
        'subscription_amendment' => 'subscription_amendment',
        'one_off' => 'one_off',
    ];

    /** Rails: QUOTE_NUMBER_REGEX (lib validation on the read surface). */
    public const QUOTE_NUMBER_REGEX = '/\AQT-\d{4}-\d{4,}\z/';

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

    /** Rails: `has_many :quote_owners, dependent: :destroy`. */
    public function quoteOwners(): HasMany
    {
        return $this->hasMany(QuoteOwner::class);
    }

    /** Rails: `has_many :owners, through: :quote_owners, source: :user`. */
    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'quote_owners',
            'quote_id',
            'user_id',
        );
    }

    /**
     * Rails: `has_many :versions, -> { order(sequential_id: :desc) }` —
     * newest version first.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(QuoteVersion::class)
            ->orderByDesc('sequential_id');
    }

    /** Rails: `has_one :current_version, -> { order(sequential_id: :desc) }`. */
    public function currentVersion()
    {
        return $this->hasOne(QuoteVersion::class)
            ->orderByDesc('sequential_id');
    }

    /**
     * Laravel-idiom alias of `versions()` — the name the scaffold (and the
     * services written against it) use for the same relation.
     */
    public function quoteVersions(): HasMany
    {
        return $this->versions();
    }

    /** Rails: `has_many :order_forms, through: :versions`. */
    public function orderForms()
    {
        return OrderForm::query()
            ->whereIn('quote_version_id', $this->versions()->select('id'));
    }

    /** Rails: `def version` on the version — the current one read off the quote. */
    public function currentVersionNumber(): ?int
    {
        return $this->currentVersion()->first()?->sequential_id;
    }

    // -- Number ---------------------------------------------------------------

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
