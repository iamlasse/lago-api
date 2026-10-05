<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\ConnectionResolvable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' UsageThreshold (app/models/usage_threshold.rb) — a fixed or
 * recurring progressive-billing threshold attached to a plan or a single
 * subscription.
 *
 * Rails' validations are enforced at the service layer here (the port has no
 * AR validation layer); the DB CHECK `usage_thresholds_check_exactly_one_parent`
 * backstops the one-of-plan-or-subscription rule. The uniqueness scopes
 * (amount per plan+recurring, single recurring per plan) are enforced by
 * UsageThresholds::UpdateService before insert, matching Rails where the
 * writes actually happen.
 */
#[Table(name: 'usage_thresholds')]
class UsageThreshold extends BaseModel
{
    use ConnectionResolvable;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'plan_id',
        'subscription_id',
        'threshold_display_name',
        'amount_cents',
        'recurring',
    ];

    protected $attributes = [
        'recurring' => false,
        'amount_cents' => 0,
    ];

    // -- Rails scopes ------------------------------------------------------------

    /** Rails: scope :recurring. */
    public function scopeRecurring(Builder $query): void
    {
        $query->where('recurring', true);
    }

    /** Rails: scope :not_recurring. */
    public function scopeNotRecurring(Builder $query): void
    {
        $query->where('recurring', false);
    }

    // -- Relations ---------------------------------------------------------------

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function appliedUsageThresholds(): HasMany
    {
        return $this->hasMany(AppliedUsageThreshold::class);
    }

    /** Rails: has_many :invoices, through: :applied_usage_thresholds. */
    public function invoices()
    {
        return $this->belongsToMany(
            Invoice::class,
            'applied_usage_thresholds',
            'usage_threshold_id',
            'invoice_id',
        );
    }

    // -- Rails instance methods --------------------------------------------------

    /** Rails: #invoice_name — the invoice line name for a passed threshold. */
    public function invoiceName(): string
    {
        return $this->threshold_display_name ?? __('invoice.usage_threshold');
    }

    /**
     * Rails: #currency — the plan's currency, else the subscription plan's,
     * else the organization default.
     */
    public function currency(): ?string
    {
        return $this->plan?->amount_currency
            ?? $this->subscription?->plan_amount_currency
            ?? $this->organization?->default_currency;
    }

    protected function casts(): array
    {
        return [
            'amount_cents' => 'int',
            'recurring' => 'boolean',
        ];
    }
}
