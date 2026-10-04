<?php

declare(strict_types=1);

namespace App\Models\Billing;

use RuntimeException;
use App\Models\Subscription;

/**
 * Port of Rails' Billing::Context (app/models/billing/context.rb) — wraps
 * the billing record the event aggregation runs against.
 *
 * TODO(port): the contract-backed variant (Rails supports `contract:` in
 * place of the subscription) arrives with the contracts slice; only the
 * subscription-backed context is implemented here, matching every current
 * caller.
 */
final class Context
{
    private function __construct(
        private readonly Subscription $subscription,
    ) {}

    /** Guard mirroring Rails' ArgumentError for unsupported delegations. */
    public function __call(string $name, array $arguments): never
    {
        throw new RuntimeException("Billing::Context does not support {$name} (subscription-backed context) — TODO(port)");
    }

    public static function fromSubscription(Subscription $subscription): self
    {
        return new self($subscription);
    }

    /** Rails: delegate :external_id. */
    public function externalId(): string
    {
        return (string) $this->subscription->external_id;
    }

    /** Rails: delegate :organization. */
    public function organization(): \App\Models\Organization
    {
        return $this->subscription->organization;
    }

    /** Rails: delegate :organization_id. */
    public function organizationId(): string
    {
        return (string) $this->subscription->organization_id;
    }

    /** Rails: delegate :customer. */
    public function customer(): \App\Models\Customer
    {
        return $this->subscription->customer;
    }

    /** Rails: delegate :started_at. */
    public function startedAt(): mixed
    {
        return $this->subscription->started_at;
    }

    /** Rails: `subscription`. */
    public function subscription(): Subscription
    {
        return $this->subscription;
    }

    /**
     * Rails: `previous_subscription_id?` / `previous_subscription_id` — the
     * upgrade/downgrade carry-over chain used by the weighted-sum recurring
     * value lookup.
     */
    public function previousSubscriptionId(): ?string
    {
        return $this->subscription->previous_subscription_id;
    }

    public function previousSubscriptionIdExists(): bool
    {
        return $this->previousSubscriptionId() !== null;
    }

    /**
     * Rails: `date_diff_with_timezone(from, to)` — whole days between two
     * instants in the customer timezone (delegated to the subscription).
     */
    public function dateDiffWithTimezone(mixed $from, mixed $to): int
    {
        $timezone = $this->customer()->applicableTimezone();

        $fromDate = \Carbon\CarbonImmutable::parse($from)->setTimezone($timezone)->startOfDay();
        $toDate = \Carbon\CarbonImmutable::parse($to)->setTimezone($timezone)->startOfDay();

        return (int) $fromDate->diffInDays($toDate);
    }
}
