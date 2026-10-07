<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Invoice;
use App\Enums\PlanInterval;
use App\Enums\InvoiceStatus;
use App\Models\Subscription;
use App\Enums\SubscriptionStatus;
use App\Models\Plan as PlanModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Field resolvers for the frozen SDL's `Plan` type (port of Rails'
 * Types::Plans::Object — app/graphql/types/plans/object.rb — plus the Plan
 * model's count methods, app/models/plan.rb:129-148).
 *
 * Plain columns (name, code, description, amountCents, amountCurrency,
 * trialPeriod, payInAdvance, billChargesMonthly, billFixedChargesMonthly,
 * invoiceDisplayName, createdAt, updatedAt, deletedAt) resolve through
 * Lighthouse's default snake_case attribute lookup, as do the plain
 * relations (organization, parent, taxes).
 *
 * TODO(port): entitlements, activityLogs (Clickhouse) and metadata (the
 * metadata arg is still ignored by Services\Plans\CreateService, so no
 * ItemMetadata rows exist for plans — see app/GraphQL/Types/Wallet.php for
 * the pattern once the create service wires it) keep the null fallback.
 */
class Plan
{
    /** Rails: plan.usage_thresholds — the kept thresholds of this plan. */
    public function usageThresholds(PlanModel $root): array
    {
        return $root->usageThresholds->all();
    }

    /**
     * Rails: plan.applicable_usage_thresholds — the override parent's
     * thresholds when this plan is an override child.
     */
    public function applicableUsageThresholds(PlanModel $root): array
    {
        return $root->applicableUsageThresholds()->all();
    }

    /**
     * Rails: Types::Plans::IntervalEnum — the enum NAME ("weekly", …); the
     * column stores the integer position (App\Enums\PlanInterval).
     */
    public function interval(PlanModel $root): ?string
    {
        $raw = $root->interval;

        return $raw === null ? null : PlanInterval::tryFrom((int) $raw)?->label();
    }

    /** Rails: object.charges.order(created_at: :asc) (object.rb:71-73). */
    public function charges(PlanModel $root): Collection
    {
        return $root->charges()->oldest()->get();
    }

    /** Rails: object.charges.count (object.rb:79-81). */
    public function chargesCount(PlanModel $root): int
    {
        return $root->charges()->count();
    }

    /** Rails: object.fixed_charges.order(created_at: :asc) (object.rb:75-77). */
    public function fixedCharges(PlanModel $root): Collection
    {
        return $root->fixedCharges()->oldest()->get();
    }

    /** Rails: object.fixed_charges.count (object.rb:83-85). */
    public function fixedChargesCount(PlanModel $root): int
    {
        return $root->fixedCharges()->count();
    }

    /** Rails: Plan#active_subscriptions_count (plan.rb:129-134) — own + override children's active subscriptions. */
    public function activeSubscriptionsCount(PlanModel $root): int
    {
        $count = $this->activeSubscriptions($root)->count();

        return $count + $this->childrenSubscriptions($root)
            ->where('status', SubscriptionStatus::Active->value)
            ->distinct()
            ->count('subscriptions.id');
    }

    /** Rails: Plan#customers_count (plan.rb:136-141) — distinct active subscribers, own + children. */
    public function customersCount(PlanModel $root): int
    {
        $count = $this->activeSubscriptions($root)
            ->distinct()
            ->count('subscriptions.customer_id');

        return $count + $this->childrenSubscriptions($root)
            ->where('status', SubscriptionStatus::Active->value)
            ->distinct()
            ->count('subscriptions.customer_id');
    }

    /** Rails: Types::Plans::Object#subscriptions_count (object.rb:87-92) — all statuses, own + children. */
    public function subscriptionsCount(PlanModel $root): int
    {
        $count = $root->subscriptions()->count();

        return $count + $this->childrenSubscriptions($root)
            ->distinct()
            ->count('subscriptions.id');
    }

    /** Rails: Plan#draft_invoices_count (plan.rb:143-148) — distinct draft invoices through the subscriptions. */
    public function draftInvoicesCount(PlanModel $root): int
    {
        return $this->draftInvoicesThrough($root->subscriptions())
            ->distinct()
            ->count('invoices.id')
            + $this->draftInvoicesThrough($this->childrenSubscriptions($root))
                ->distinct()
                ->count('invoices.id');
    }

    /** Rails: Types::Plans::Object#is_overridden (object.rb:94-96). */
    public function isOverridden(PlanModel $root): bool
    {
        return $root->parent_id !== null;
    }

    /** Rails: has_active_subscriptions (object.rb:98-104) — includes the override children. */
    public function hasActiveSubscriptions(PlanModel $root): bool
    {
        return $this->activeSubscriptions($root)->exists()
            || $this->childrenSubscriptions($root)
                ->where('status', SubscriptionStatus::Active->value)
                ->exists();
    }

    /** Rails: has_charges (object.rb:107-109). */
    public function hasCharges(PlanModel $root): bool
    {
        return $root->charges()->exists();
    }

    /** Rails: has_fixed_charges (object.rb:111-113). */
    public function hasFixedCharges(PlanModel $root): bool
    {
        return $root->fixedCharges()->exists();
    }

    /** Rails: has_customers (object.rb:116-118) — "if it has active subscriptions, it has customers". */
    public function hasCustomers(PlanModel $root): bool
    {
        return $this->hasActiveSubscriptions($root);
    }

    /** Rails: has_draft_invoices (object.rb:120-126) — includes the override children. */
    public function hasDraftInvoices(PlanModel $root): bool
    {
        return $this->draftInvoicesThrough($root->subscriptions())->exists()
            || $this->draftInvoicesThrough($this->childrenSubscriptions($root))->exists();
    }

    /** Rails: has_overridden_plans (object.rb:128-130) — children exist. */
    public function hasOverriddenPlans(PlanModel $root): bool
    {
        return $root->children()->exists();
    }

    /** Rails: has_subscriptions (object.rb:132-138) — includes the override children. */
    public function hasSubscriptions(PlanModel $root): bool
    {
        return $root->subscriptions()->exists()
            || $this->childrenSubscriptions($root)->exists();
    }

    /**
     * Rails: has_one :minimum_commitment, -> { where(commitment_type:
     * :minimum_commitment) } (app/models/plan.rb:13) — the port models it as
     * a constrained has_many, so surface the single row.
     */
    public function minimumCommitment(PlanModel $root): ?\App\Models\Commitment
    {
        return $root->minimumCommitment()->first();
    }

    // -- Helpers ------------------------------------------------------------------

    /** The plan's own active subscriptions. */
    private function activeSubscriptions(PlanModel $root): Relation
    {
        return $root->subscriptions()->where('status', SubscriptionStatus::Active->value);
    }

    /** The override children's subscriptions — `children.joins(:subscriptions)`. */
    private function childrenSubscriptions(PlanModel $root): Builder
    {
        return Subscription::query()->whereIn(
            'plan_id',
            $root->children()->select('plans.id'),
        );
    }

    /**
     * Rails: `subscriptions.joins(:invoices).merge(Invoice.draft)` — invoices
     * linked through invoice_subscriptions in the draft state (the port's
     * Subscription model has no `invoices()` hasManyThrough yet).
     */
    private function draftInvoicesThrough(Relation|Builder $subscriptions): Builder
    {
        return Invoice::query()
            ->join(
                'invoice_subscriptions',
                'invoice_subscriptions.invoice_id',
                '=',
                'invoices.id',
            )
            ->whereIn(
                'invoice_subscriptions.subscription_id',
                $subscriptions->select('subscriptions.id'),
            )
            ->where('invoices.status', InvoiceStatus::Draft->value);
    }
}
