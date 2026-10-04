<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use RuntimeException;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\BillableMetric;
use App\Enums\SubscriptionStatus;
use Illuminate\Support\Collection;
use App\Jobs\Events\PayInAdvanceJob;
use Illuminate\Database\Eloquent\Builder;

/**
 * Port of Rails' Events::PostProcessService
 * (app/services/events/post_process_service.rb): the post-ingestion step
 * run by Events::PostProcessJob.
 *
 * This slice ports the customer/subscription resolution the downstream
 * consumers rely on (exposed for the unit tests — Rails specs exercise
 * them through the job's behavior). The consumers themselves land with
 * their own slices:
 *
 * - TODO(port): create_enriched_events (Events::EnrichService + the
 *   postgres_enriched_events feature flag — the enriched_events table is
 *   now in the frozen schema but the enrichment pipeline is not ported);
 * - TODO(port): track_subscription_activity
 *   (UsageMonitoring::TrackSubscriptionActivityService);
 * - flag_wallets_for_refresh — WIRED (the wallet refresh chain landed:
 *   Clock\RefreshWalletsOngoingBalanceJob picks the flag up);
 * - TODO(port): check_targeted_wallets (wallets slice — events targeting
 *   wallets + the event.error webhook);
 * - handle_pay_in_advance — WIRED (Events\PayInAdvanceJob);
 * - TODO(port): the RecordNotUnique rescue delivering the event.error
 *   webhook (fires from the enriched-events insert today).
 */
class PostProcessService extends BaseService
{
    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(private readonly Event $event)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('event');

        // create_enriched_events / track_subscription_activity /
        // check_targeted_wallets — TODO(port), see the class docblock.

        $this->customer()?->flagWalletsForRefresh();

        $this->handlePayInAdvance();

        $result->event = $this->event;

        return $result;
    }

    /**
     * Rails: `customer` — the customer of the first matching subscription
     * (or of the fallback one).
     */
    public function customer()
    {
        return ($this->subscriptions()->first() ?? $this->fallbackSubscription())?->customer;
    }

    /**
     * Rails: `subscriptions` — the org's non-incomplete subscriptions for the
     * event's external_subscription_id, narrowed to the event's timestamp
     * window, terminated-first ordering.
     */
    public function subscriptions(): Collection
    {
        if (array_key_exists('subscriptions', $this->memo)) {
            return $this->memo['subscriptions'];
        }

        $event = $this->event;

        $subscriptions = $event->organization->subscriptions()
            ->where('external_id', $event->external_subscription_id)
            ->whereNot('status', SubscriptionStatus::Incomplete->value)
            ->whereRaw("date_trunc('millisecond', started_at::timestamp) <= ?::timestamp", [$event->timestamp])
            ->where(function (Builder $query) use ($event): void {
                $query->whereNull('terminated_at')
                    ->orWhereRaw("date_trunc('millisecond', terminated_at::timestamp) >= ?", [$event->timestamp]);
            })
            ->orderByRaw('terminated_at DESC NULLS FIRST, started_at DESC')
            ->get();

        return $this->memo['subscriptions'] = $subscriptions;
    }

    /**
     * Rails: `active_subscription` — raises when several subscriptions are
     * active; falls back to the recurring-event fallback otherwise.
     */
    public function activeSubscription()
    {
        $active = $this->subscriptions()->filter(fn ($subscription): bool => $subscription->active());

        if ($active->count() > 1) {
            throw new RuntimeException('Multiple active subscriptions found');
        }

        return $active->first() ?? $this->fallbackSubscription();
    }

    /**
     * Rails: `fallback_subscription` — when a backdated recurring event
     * matches no subscription, attach it to the currently active one.
     */
    public function fallbackSubscription()
    {
        if ($this->subscriptions()->isNotEmpty()) {
            return null;
        }

        if (! $this->billableMetric()?->recurring) {
            return null;
        }

        return $this->event->organization->subscriptions()
            ->where('external_id', $this->event->external_subscription_id)
            ->active()
            ->orderByDesc('started_at')
            ->first();
    }

    /** Rails: `billable_metric` — resolved by the event's code. */
    public function billableMetric(): ?BillableMetric
    {
        return $this->memo['billable_metric'] ??= $this->event->organization
            ->billableMetrics()
            ->where('code', $this->event->code)
            ->first();
    }

    /**
     * Rails: `handle_pay_in_advance` — enqueue the per-event billing when
     * the event's billable metric carries pay-in-advance charges.
     */
    private function handlePayInAdvance(): void
    {
        $billableMetric = $this->billableMetric();

        if ($billableMetric === null) {
            return;
        }

        $hasPayInAdvanceCharges = $billableMetric
            ->charges()
            ->where('charges.pay_in_advance', true)
            ->exists();

        if (! $hasPayInAdvanceCharges) {
            return;
        }

        PayInAdvanceJob::dispatch($this->event);
    }
}
