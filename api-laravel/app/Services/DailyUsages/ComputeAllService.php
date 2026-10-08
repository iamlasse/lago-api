<?php

declare(strict_types=1);

namespace App\Services\DailyUsages;

use Throwable;
use App\Models\Charge;
use App\Models\DailyUsage;
use Carbon\CarbonInterface;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Enums\AggregationType;
use App\Jobs\DailyUsages\ComputeJob;
use Illuminate\Database\Eloquent\Builder;
use App\Services\Subscriptions\BillingDateQuery;

/**
 * Port of Rails' DailyUsages::ComputeAllService
 * (app/services/daily_usages/compute_all_service.rb) — the hourly clock
 * fan-out: selects, per organization, the active subscriptions whose
 * yesterday-in-their-timezone daily usage must be (re)computed, and
 * enqueues one DailyUsages\ComputeJob per subscription.
 *
 * TODO(port): FillHistoryService / FillHistoryJob (the API-triggered
 * backfill — no callers outside specs in the Rails tree today).
 */
class ComputeAllService extends BaseService
{
    public const int ENQUEUE_BATCH_SIZE = 1000;

    /** Rails: LAGO_DAILY_USAGE_SCHEDULING_JITTER_SECONDS, default 30.minutes. */
    public const int DEFAULT_SCHEDULING_INTERVAL = 1800;

    public function __construct(
        private readonly CarbonInterface $timestamp,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        $this->withRevenueAnalyticsSupport()->chunkById(
            1000,
            function ($organizations): void {
                foreach ($organizations as $organization) {
                    try {
                        $this->scheduleOrganization($organization);
                    } catch (Throwable $e) {
                        // Nothing recomputes an older day and the clock job is not
                        // retried, so letting one organization's failure abort the
                        // run would permanently lose the day for all the others.
                        $this->reportFailure($organization, $e);
                    }
                }
            }
        );

        return $result;
    }

    private function scheduleOrganization(Organization $organization): void
    {
        $ids = [];

        // Each leg runs its (cheap, indexed) selection query ONCE. We never
        // iterate the heavy relation with chunkById, which would re-execute
        // the whole query per 1000-row batch.
        foreach ($this->eventSubscriptions($organization)->pluck('subscriptions.id') as $id) {
            $ids[$id] = true;
        }
        foreach ($this->timeDependentSubscriptions($organization)->pluck('subscriptions.id') as $id) {
            $ids[$id] = true;
        }
        foreach ($this->recurringRolloverSubscriptions($organization)->pluck('subscriptions.id') as $id) {
            $ids[$id] = true;
        }

        // Drop subscriptions already computed for yesterday. Subtracting here
        // runs the dedup query once per organization, instead of three times
        // (once per leg) if it lived in base_scope.
        foreach ($this->alreadyComputedSubscriptionIds($organization) as $id) {
            unset($ids[$id]);
        }

        $this->enqueue(array_keys($ids));
    }

    private function reportFailure(Organization $organization, Throwable $error): void
    {
        // Rails logs a warn line and reports to Sentry with the context as
        // extra; the port logs through the default handler.
        // TODO(port): Sentry.capture_exception(error, extra: context).
        \Illuminate\Support\Facades\Log::warning(
            'Daily usage computation skipped for an organization: '.$error->getMessage(),
            [
                'organization_id' => $organization->id,
                'timestamp' => $this->timestamp->toIso8601String(),
                'exception' => $error::class,
            ]
        );
    }

    /**
     * Recompute every day for subscriptions that received an event recently:
     * their usage may have changed. Restricted to customers entering a new
     * day in their timezone.
     */
    private function eventSubscriptions(Organization $organization): Builder
    {
        return $this->baseScope($organization)
            ->join('customers', 'customers.id', '=', 'subscriptions.customer_id')
            ->join('billing_entities', 'billing_entities.id', '=', 'customers.billing_entity_id')
            ->whereRaw($this->timezoneWindowSql(), [$this->now()])
            ->where('subscriptions.last_received_event_on', '>=', $this->yesterday()->toDateString());
    }

    /**
     * Recompute every day for subscriptions whose usage changes between
     * billing boundaries WITHOUT new events (prorated charges, weighted_sum
     * aggregations), even when no event was received.
     *
     * The time-dependent plan ids resolve as a subquery so Postgres can hash
     * semi-join, instead of a correlated EXISTS (which is evaluated per row
     * and is catastrophic on plan-per-subscription organizations).
     */
    private function timeDependentSubscriptions(Organization $organization): Builder
    {
        return $this->baseScope($organization)
            ->whereIn('subscriptions.plan_id', $this->timeDependentPlanIds($organization))
            ->join('customers', 'customers.id', '=', 'subscriptions.customer_id')
            ->join('billing_entities', 'billing_entities.id', '=', 'customers.billing_entity_id')
            ->whereRaw($this->timezoneWindowSql(), [$this->now()])
            ->where(function (Builder $query): void {
                $query->whereNull('subscriptions.last_received_event_on')
                    ->orWhere('subscriptions.last_received_event_on', '<', $this->yesterday()->toDateString());
            });
    }

    /**
     * Recurring metrics are constant between events, so they only need to be
     * recomputed once per period, to capture the carried-over value of the
     * new period.
     *
     * ComputeService skips the billing day itself (its usage comes from the
     * periodic invoice), so the carry-over row (usage_date = period start) is
     * created on the run of the *next* day. We therefore select subscriptions
     * whose period rolled over yesterday (`timestamp - 1.day`).
     */
    private function recurringRolloverSubscriptions(Organization $organization): Builder
    {
        $scope = $this->baseScope($organization)
            ->whereIn('subscriptions.plan_id', $this->recurringPlanIds($organization))
            ->where(function (Builder $query): void {
                $query->whereNull('subscriptions.last_received_event_on')
                    ->orWhere('subscriptions.last_received_event_on', '<', $this->yesterday()->toDateString());
            });

        return BillingDateQuery::call(
            subscriptions: $scope,
            timestamp: $this->timestamp->copy()->subDay(),
        )->subscriptions
            // BillingDateQuery already joins customers + billing_entities.
            ->whereRaw($this->timezoneWindowSql(), [$this->now()]);
    }

    private function baseScope(Organization $organization): Builder
    {
        return Subscription::query()
            ->where('subscriptions.organization_id', $organization->id)
            ->active()
            ->where('subscriptions.skip_daily_usage', false);
    }

    /**
     * Plans with a charge whose usage changes daily without events: prorated
     * charges or weighted_sum aggregations. Returned as a query so it
     * composes as a subquery (semi-join).
     *
     * NOTE (ported from Rails): fixed charges are intentionally excluded —
     * CustomerUsageService only computes usage charges, so a prorated fixed
     * charge does not change the daily usage value.
     */
    private function timeDependentPlanIds(Organization $organization): Builder
    {
        return Charge::query()
            ->join('plans', 'plans.id', '=', 'charges.plan_id')
            ->join('billable_metrics', 'billable_metrics.id', '=', 'charges.billable_metric_id')
            ->where('plans.organization_id', $organization->id)
            ->whereNull('charges.deleted_at')
            ->whereNull('billable_metrics.deleted_at')
            ->where(function (Builder $query): void {
                $query->where('charges.prorated', true)
                    ->orWhere('billable_metrics.aggregation_type', AggregationType::WeightedSumAgg->value);
            })
            ->select('charges.plan_id');
    }

    /** Plans with a recurring billable metric. Returned as a query so it composes as a subquery. */
    private function recurringPlanIds(Organization $organization): Builder
    {
        return Charge::query()
            ->join('plans', 'plans.id', '=', 'charges.plan_id')
            ->join('billable_metrics', 'billable_metrics.id', '=', 'charges.billable_metric_id')
            ->where('plans.organization_id', $organization->id)
            ->whereNull('charges.deleted_at')
            ->whereNull('billable_metrics.deleted_at')
            ->where('billable_metrics.recurring', true)
            ->select('charges.plan_id');
    }

    /**
     * Subscriptions that already have a daily usage for yesterday (in the
     * customer's timezone). Pre-filters on the indexed raw usage_date before
     * the timezone-aware match, to avoid scanning the org's whole history.
     *
     * @return list<string>
     */
    private function alreadyComputedSubscriptionIds(Organization $organization): array
    {
        return DailyUsage::query()
            ->usageDateInTimezone($this->yesterday())
            ->where('daily_usages.organization_id', $organization->id)
            ->whereBetween('daily_usages.usage_date', [
                $this->yesterday()->copy()->subDay()->toDateString(),
                $this->timestamp->copy()->setTimezone('UTC')->toDateString(),
            ])
            ->pluck('daily_usages.subscription_id')
            ->all();
    }

    /**
     * Load the matched subscriptions by primary key (cheap, indexed) and
     * enqueue, in bounded slices so we never hold more than
     * ENQUEUE_BATCH_SIZE records in memory at once.
     *
     * @param  list<string>  $ids
     */
    private function enqueue(array $ids): void
    {
        foreach (array_chunk($ids, self::ENQUEUE_BATCH_SIZE) as $batch) {
            $subscriptions = Subscription::query()->whereIn('subscriptions.id', $batch)->get();

            foreach ($subscriptions as $subscription) {
                dispatch(new ComputeJob($subscription, $this->timestamp))
                    ->delay($this->jobWaitTime());
            }
        }
    }

    private function yesterday(): CarbonInterface
    {
        return $this->dateInUtc()->subDay();
    }

    /** Rails: `timestamp` bound for the SQL fragments (UTC datetime string). */
    private function now(): string
    {
        return $this->timestamp->copy()->setTimezone('UTC')->toDateTimeString();
    }

    private function dateInUtc(): CarbonInterface
    {
        return $this->timestamp->copy()->setTimezone('UTC')->startOfDay();
    }

    /**
     * Rails: `Organization.with_revenue_analytics_support` — only the
     * organizations with the `revenue_analytics` premium integration get
     * their daily usages computed.
     *
     * TODO(port): the Rails scope is not defined in the Rails snapshot
     * (referenced from this service only); the port follows the codebase's
     * premium-integration convention (License::premium() + the org's
     * premium_integrations list).
     */
    private function withRevenueAnalyticsSupport(): Builder
    {
        $organizations = Organization::query();

        if (! \App\Support\License::premium()) {
            // No license — no organization ever matches.
            return $organizations->whereRaw('1 = 0');
        }

        return $organizations->whereRaw(
            'premium_integrations @> ARRAY[?]::varchar[]',
            ['revenue_analytics']
        );
    }

    /**
     * Only schedule customers entering a new day in their timezone; the
     * hourly clock catches each timezone as it crosses midnight.
     */
    private function timezoneWindowSql(): string
    {
        $atTimeZone = "::timestamptz AT TIME ZONE COALESCE(customers.timezone, billing_entities.timezone, 'UTC')";

        return "DATE_PART('hour', (?{$atTimeZone})) IN (0, 1, 2)";
    }

    /**
     * Randomize job wait time to distribute load across the system. This
     * prevents a thundering herd, and interleaves jobs from different
     * organizations (subscriptions within an org usually share a load
     * profile).
     */
    private function jobWaitTime(): int
    {
        return random_int(0, $this->schedulingInterval());
    }

    private function schedulingInterval(): int
    {
        $rawValue = env('LAGO_DAILY_USAGE_SCHEDULING_JITTER_SECONDS');
        $parsed = ($rawValue !== null && $rawValue !== '' && is_numeric($rawValue))
            ? (int) $rawValue
            : null;

        if ($parsed !== null && $parsed <= 0) {
            $parsed = null;
        }

        return $parsed ?? self::DEFAULT_SCHEDULING_INTERVAL;
    }
}
