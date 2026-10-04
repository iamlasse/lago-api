<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Port of Rails' Entitlement::Feature (app/models/entitlement/feature.rb) —
 * a feature an organization can grant on plans / subscriptions, with the
 * privileges that parametrize it. Stored in `entitlement_features`.
 */
#[Fillable([
    'organization_id',
    'code',
    'name',
    'description',
    'deleted_at',
])]
#[Table(name: 'entitlement_features')]
class Feature extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    /**
     * Rails: `attr_writer :subscriptions_count` — a non-column attribute the
     * GraphQL features resolver preloads (Feature::preloadSubscriptionsCount).
     */
    public ?int $subscriptionsCount = null;

    /**
     * Rails: `self.preload_subscriptions_count` — batches the counts through
     * SubscriptionsCountQuery and assigns them onto the instances.
     *
     * @param  iterable<self>  $features
     * @return iterable<self>
     */
    public static function preloadSubscriptionsCount(Organization $organization, iterable $features): iterable
    {
        $features = is_array($features) ? $features : iterator_to_array($features);

        $counts = \App\Queries\SubscriptionsCountQuery::call(
            organization: $organization,
            filters: [
                'feature_ids' => array_map(fn (self $feature): string => (string) $feature->id, $features),
            ],
        )->features;

        foreach ($features as $feature) {
            $feature->subscriptionsCount = $counts[$feature->id] ?? 0;
        }

        return $features;
    }

    // -- Relationships ----------------------------------------------------------

    /** Rails: `has_many :privileges, foreign_key: "entitlement_feature_id", dependent: :destroy`. */
    public function privileges(): HasMany
    {
        return $this->hasMany(Privilege::class, 'entitlement_feature_id');
    }

    /** Rails: `has_many :entitlements, foreign_key: "entitlement_feature_id", dependent: :destroy`. */
    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class, 'entitlement_feature_id');
    }

    /** Rails: `has_many :entitlement_values, through: :entitlements, source: :values, dependent: :destroy`. */
    public function entitlementValues(): HasManyThrough
    {
        return $this->hasManyThrough(
            EntitlementValue::class,
            Entitlement::class,
            'entitlement_feature_id',
            'entitlement_entitlement_id',
        );
    }

    /** Rails: `has_many :plans, through: :entitlements`. */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'entitlement_entitlements', 'entitlement_feature_id', 'plan_id')
            ->whereNull('entitlement_entitlements.deleted_at')
            ->whereNull('plans.deleted_at');
    }

    // Rails: `has_many :catalog_plans, through: :entitlements` — catalog plans
    // are not part of the legacy-engine port (the frozen schema keeps the
    // catalog_plan_id column; nothing writes it).

    // -- Validations ------------------------------------------------------------

    /**
     * Port of the Rails model validations — `field => [api error codes]`,
     * empty when valid.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        if (($this->code ?? '') === '') {
            $errors['code'] = ['value_is_mandatory'];
        } elseif (mb_strlen((string) $this->code) > 255) {
            $errors['code'] = ['value_is_too_long'];
        }

        if ($this->name !== null && mb_strlen((string) $this->name) > 255) {
            $errors['name'] = ['value_is_too_long'];
        }

        if ($this->description !== null && mb_strlen((string) $this->description) > 600) {
            $errors['description'] = ['value_is_too_long'];
        }

        return $errors;
    }

    // -- Domain methods (ports of the Rails instance methods) ---------------------

    /**
     * Rails: `subscriptions_count` —
     * `Subscription.joins(:plan).where(status: [:active, :pending])
     *   .where(plan: plans).or(...where(plan: {parent: plans})).count`
     * — the active / pending subscriptions on the plans carrying this
     * feature (children included, through their parent).
     */
    public function computeSubscriptionsCount(): int
    {
        $featurePlanIds = $this->plans()->select('plans.id');

        return Subscription::query()
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->whereNull('plans.deleted_at')
            ->whereIn('subscriptions.status', [0, 1]) // Rails: [:active, :pending]
            ->where(function (Builder $query) use ($featurePlanIds): void {
                $query->whereIn('subscriptions.plan_id', $featurePlanIds)
                    ->orWhereIn('plans.parent_id', $featurePlanIds);
            })
            ->count();
    }

    /** Rails: `def subscriptions_count` — the preloaded value when set. */
    public function subscriptionsCount(): int
    {
        return $this->subscriptionsCount ?? $this->computeSubscriptionsCount();
    }

    // -- Scopes ------------------------------------------------------------------

    /** Rails: `Entitlement::Feature.where(organization:)` + ransack OR search. */
    public function scopeOfOrganization(Builder $query, Organization $organization): Builder
    {
        return $query->where('organization_id', $organization->id);
    }
}
