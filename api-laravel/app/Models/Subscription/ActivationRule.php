<?php

declare(strict_types=1);

namespace App\Models\Subscription;

use ArrayAccess;
use LogicException;
use App\Models\BaseModel;
use App\Models\Subscription;
use InvalidArgumentException;
use App\Models\Concerns\ConnectionResolvable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Services\Subscriptions\ActivationRules\Payment\EvaluateService as PaymentEvaluateService;

/**
 * Frozen-schema model for `subscription_activation_rules`. Port of the Rails
 * Subscription::ActivationRule model
 * (app/models/subscription/activation_rule.rb).
 *
 * Rails uses STI on the `type` PG enum: `Subscription::ActivationRule::Payment`
 * is the only type today (subscription_activation_rule_types). The subclass is
 * hydrated from rows via newFromBuilder(), and saving a subclass stamps its
 * own `type`, mirroring Rails' STI write behaviour.
 *
 * Statuses are the PG enum subscription_activation_rule_statuses (strings,
 * validated by the DB).
 */
#[Fillable([
    'organization_id',
    'subscription_id',
    'type',
    'timeout_hours',
    'status',
    'expires_at',
])]
#[Table(name: 'subscription_activation_rules')]
class ActivationRule extends BaseModel
{
    use BelongsToOrganization;
    use ConnectionResolvable;
    use HasFactory;

    /** Rails: STI_MAPPING — type value => subclass. */
    public const STI_MAPPING = [
        'payment' => ActivationRule\Payment::class,
    ];

    /** Rails: STATUSES (PG enum subscription_activation_rule_statuses). */
    public const STATUSES = [
        'inactive' => 'inactive',
        'pending' => 'pending',
        'satisfied' => 'satisfied',
        'declined' => 'declined', // rule was applicable but declined (e.g., declined after undergoing a manual approval process)
        'failed' => 'failed',
        'expired' => 'expired',
        'not_applicable' => 'not_applicable',
    ];

    /** Rails: FULFILLED_STATUSES. */
    public const FULFILLED_STATUSES = ['satisfied', 'not_applicable'];

    /** Rails: REJECTED_STATUSES. */
    public const REJECTED_STATUSES = ['failed', 'expired', 'declined'];

    /** Rails: TYPES. */
    public const TYPES = [
        'payment' => 'payment',
    ];

    /** NOT NULL columns with DB defaults, mirrored on new instances. */
    protected $attributes = [
        'timeout_hours' => 0,
        'status' => 'inactive',
    ];

    // -- STI ---------------------------------------------------------------------

    /** The `type` value this class writes (Rails: `sti_name`). */
    public static function stiType(): ?string
    {
        return null;
    }

    /** Rails: `self.find_sti_class(type_name)`. */
    public static function stiClassFor(string $type): string
    {
        return static::STI_MAPPING[$type] ?? static::class;
    }

    /** Rails: `self.sti_name` — the type value for this class. */
    public static function stiName(): string
    {
        return array_search(static::class, static::STI_MAPPING, true) ?: '';
    }

    /** Rails: `evaluate_service_class` — "Subscriptions::ActivationRules::<Type>::EvaluateService". */
    public static function evaluateServiceClass(): string
    {
        // PHP cannot constantize a string safely — the mapping is explicit,
        // mirroring the Rails constantize by convention.
        return match (static::stiName()) {
            'payment' => PaymentEvaluateService::class,
            default => throw new LogicException('No evaluate service for type '.static::stiName()),
        };
    }

    public function newFromBuilder($attributes = [], $connection = null): static
    {
        $attrs = $attributes instanceof ArrayAccess ? $attributes->toArray() : (array) $attributes;

        $type = $attrs['type'] ?? null;
        $class = is_string($type) && $type !== '' ? static::stiClassFor($type) : static::class;

        // A hydrated row of a known type becomes its STI subclass, exactly
        // like Rails' find_sti_class. The subclass instance satisfies the
        // `static` return type (it is an instance of this class).
        $instance = (new $class)->newInstance([], true);

        $instance->setRawAttributes($attrs, true);

        if ($connection !== null) {
            $instance->setConnection($connection);
        }

        $instance->fireModelEvent('retrieved', false);

        return $instance;
    }

    // -- Relationships -----------------------------------------------------------

    /** Rails: `belongs_to :subscription`. */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    // -- Scopes ------------------------------------------------------------------

    /** Rails: `scope :expirable, -> { pending.where("expires_at <= ?", Time.current) }`. */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function expirable($query): mixed
    {
        return $query->where('status', 'pending')->where('expires_at', '<=', now());
    }

    /** Rails: `scope :rejected`. */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function rejected($query): mixed
    {
        return $query->whereIn('status', static::REJECTED_STATUSES);
    }

    /** Rails: `scope :fulfilled`. */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function fulfilled($query): mixed
    {
        return $query->whereIn('status', static::FULFILLED_STATUSES);
    }

    // -- Domain methods ----------------------------------------------------------

    /**
     * Rails: `#applicable?` — type-specific, implemented by each STI
     * subclass; the base class is abstract in Rails.
     */
    public function applicable(): bool
    {
        throw new LogicException(class_basename(static::class).'#applicable() must be implemented');
    }

    /** Rails: `#evaluate!` — dispatches to the type's evaluate service. */
    public function evaluate(): void
    {
        static::evaluateServiceClass()::callBang(rule: $this);
    }

    // -- Status transitions (Rails' enum bang methods, validated by the PG enum) --

    public function transitionTo(string $status): static
    {
        if (! in_array($status, static::STATUSES, true)) {
            throw new InvalidArgumentException("'{$status}' is not a valid activation rule status");
        }

        $this->status = $status;

        return $this;
    }

    protected static function booted(): void
    {
        // Rails' STI stamps the discriminator on insert.
        static::creating(function (self $rule): void {
            if ($rule->type === null) {
                $rule->type = static::stiType();
            }
        });
    }

    /** The Rails factory (:subscription_activation_rule) is shared across types. */
    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Database\Factories\Subscription\ActivationRuleFactory::new();
    }

    protected function casts(): array
    {
        return [
            'timeout_hours' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
