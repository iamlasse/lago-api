<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\IntegrationCustomer;
use App\Models\BillingObjectConnection;

/**
 * Port of Rails' ConnectionResolvable concern
 * (app/models/concerns/connection_resolvable.rb): resolves the effective
 * connection of each category (payment / tax / accounting / crm) for a
 * billing object, cascading an explicit per-object override to the customer
 * default.
 *
 * Expects the consuming model to provide:
 *  - billingObjectConnections(): HasMany<BillingObjectConnection>
 *  - customer(): BelongsTo<Customer>
 */
trait ConnectionResolvable
{
    /**
     * Read-side only: the absence of an override row is reported as "inherit".
     * The column itself only ever holds "specific" or "skip".
     */
    public const INHERIT_BEHAVIOR = 'inherit';

    /**
     * Rails: `connection_routing` — the routing of every category, for read
     * surfaces, keyed by category: the stored behavior ("inherit" when no
     * override row exists) alongside the code of the connection actually in
     * effect.
     *
     * @return array<string, array{behavior: string, code: string|null}>
     */
    public function connectionRouting(): array
    {
        $overrides = $this->billingObjectConnections->keyBy('category');

        $routing = [];

        foreach (array_values(BillingObjectConnection::CATEGORIES) as $category) {
            $override = $overrides->get($category);

            $connection = match (true) {
                $override === null => $this->customerDefaultConnection($category),
                $override->behavior === 'skip' => null,
                default => $this->overrideConnection($override, $category),
            };

            $routing[$category] = [
                'behavior' => $override?->behavior ?? self::INHERIT_BEHAVIOR,
                'code' => $connection?->code,
            ];
        }

        return $routing;
    }

    /** @return IntegrationCustomer|\App\Models\PaymentProviderCustomer|null */
    protected function overrideConnection(BillingObjectConnection $override, string $category): ?object
    {
        return $category === 'payment'
            ? $override->paymentProviderCustomer
            : $override->integrationCustomer;
    }

    /** @return IntegrationCustomer|\App\Models\PaymentProviderCustomer|null */
    protected function customerDefaultConnection(string $category): ?object
    {
        $customer = $this->customer;

        if ($customer === null) {
            return null;
        }

        $connections = $category === 'payment'
            ? $customer->paymentProviderCustomers->all()
            : $customer->integrationCustomers
                ->filter(fn (IntegrationCustomer $connection): bool => $connection->category === $category)
                ->values()
                ->all();

        return $this->defaultAmong($connections);
    }

    /** Rails: `default_among` — the only one, else the flagged default. */
    protected function defaultAmong(array $connections): ?object
    {
        if (count($connections) === 1) {
            return $connections[0];
        }

        foreach ($connections as $connection) {
            if ($connection->is_default === true) {
                return $connection;
            }
        }

        return null;
    }
}
