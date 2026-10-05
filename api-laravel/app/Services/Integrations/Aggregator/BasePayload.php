<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator;

use App\Models\Fee;
use App\Models\Integration;

/**
 * Port of Rails' Integrations::Aggregator::BasePayload
 * (app/services/integrations/aggregator/base_payload.rb) — the item-code
 * mapping layer of the provider payloads.
 *
 * TODO(port): the IntegrationMappings::BaseMapping /
 * IntegrationCollectionMappings::BaseCollectionMapping models and their
 * CRUD are a separate slice — every lookup therefore resolves to nil here,
 * which is exactly what Rails produces for an integration with no mappings
 * configured (item_code is optional on both providers' payloads).
 */
abstract class BasePayload
{
    public function __construct(
        protected readonly Integration $integration,
        protected readonly ?object $billingEntity,
    ) {}

    /**
     * Rails: `mapped_item(fee)` — the fee kind dispatch. Returns the mapping
     * row (only its external_id is consumed by the payloads).
     */
    public function mappedItem(Fee $fee): ?object
    {
        if ($fee->isCharge()) {
            return $this->billable_metric_item($fee);
        }

        if ($fee->add_on_id !== null) {
            return $this->add_on_item($fee);
        }

        if ($fee->typeEnum()?->name === 'FixedCharge') {
            return $this->fixed_charge_item($fee);
        }

        if ($fee->typeEnum()?->name === 'Commitment') {
            return $this->commitment_item();
        }

        if ($fee->typeEnum()?->name === 'Subscription') {
            return $this->subscription_item();
        }

        return null;
    }

    /** Rails: `billable_metric_item` — lookup_mapping("BillableMetric", …). */
    protected function billable_metric_item(Fee $fee): ?object
    {
        return $this->lookup_mapping('BillableMetric', $fee->billable_metric_id);
    }

    /** Rails: `add_on_item`. */
    protected function add_on_item(Fee $fee): ?object
    {
        return $this->lookup_mapping('AddOn', $fee->add_on_id);
    }

    /** Rails: `fixed_charge_item`. */
    protected function fixed_charge_item(Fee $fee): ?object
    {
        return $this->lookup_mapping('AddOn', $fee->fixedCharge?->add_on_id);
    }

    /** Rails: `commitment_item` — lookup_collection_mapping(:minimum_commitment). */
    protected function commitment_item(): ?object
    {
        return $this->lookup_collection_mapping('minimum_commitment');
    }

    /** Rails: `subscription_item` — lookup_collection_mapping(:subscription_fee). */
    protected function subscription_item(): ?object
    {
        return $this->lookup_collection_mapping('subscription_fee');
    }

    /** Rails: `tax_item` — lookup_collection_mapping(:tax, with_fallback_item: false). */
    protected function tax_item(): ?object
    {
        return $this->lookup_collection_mapping('tax', with_fallback_item: false);
    }

    /** Rails: `account_item` — lookup_collection_mapping(:account). */
    protected function account_item(): ?object
    {
        return $this->lookup_collection_mapping('account');
    }

    /** Rails: `coupon_item` — lookup_collection_mapping(:coupon). */
    protected function coupon_item(): ?object
    {
        return $this->lookup_collection_mapping('coupon');
    }

    /** Rails: `credit_item` — lookup_collection_mapping(:prepaid_credit). */
    protected function credit_item(): ?object
    {
        return $this->lookup_collection_mapping('prepaid_credit');
    }

    /** Rails: `credit_note_item` — lookup_collection_mapping(:credit_note). */
    protected function credit_note_item(): ?object
    {
        return $this->lookup_collection_mapping('credit_note');
    }

    /**
     * Rails: `amount(amount_cents, resource:)` — cents to major units as a
     * string, rounded, over the resource currency's subunit.
     */
    protected function amount(int|float|string|null $amountCents, object $resource): string
    {
        $currency = $resource->amount_currency ?? $resource->currency ?? 'USD';

        return (string) (round((float) $amountCents) / \App\Support\Currency::subunitToUnit((string) $currency));
    }

    // -- Mapping lookups (TODO(port) — the mappings slice) --------------------

    /** @return array<int, object>|null */
    protected function lookup_collection_mapping(string $mappingType, bool $with_fallback_item = true): ?object
    {
        return null;
    }

    /** @return array<int, object>|null */
    protected function lookup_mapping(string $mappableType, string|int|null $mappableId): ?object
    {
        return null;
    }

    protected function fallback_item(string $scope): ?object
    {
        return null;
    }

    protected function formatted_date(?string $date): ?string
    {
        return $date !== null ? \Illuminate\Support\Carbon::parse($date)->toDateString() : null;
    }
}
