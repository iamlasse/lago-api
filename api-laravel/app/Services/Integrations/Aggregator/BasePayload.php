<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator;

use App\Models\Fee;
use App\Models\Integration;

/**
 * Port of Rails' Integrations::Aggregator::BasePayload
 * (app/services/integrations/aggregator/base_payload.rb) — the item-code
 * mapping layer of the provider payloads, backed by the
 * IntegrationMappings / IntegrationCollectionMappings rows (a missing
 * mapping resolves to nil, exactly like Rails for an integration with no
 * mappings configured — item_code is optional on both providers'
 * payloads).
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

    /** Rails: `tax_item_complete?` — nexus, type and code all present. */
    protected function tax_item_complete(): bool
    {
        return $this->tax_item()?->tax_nexus !== null
            && $this->tax_item()?->tax_type !== null
            && $this->tax_item()?->tax_code !== null;
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

    // -- Mapping lookups (the IntegrationMappings / IntegrationCollectionMappings
    //    slice) -----------------------------------------------------------------

    /**
     * Rails: `fallback_item(scope)` — the :fallback_item collection mapping,
     * for the billing entity when there is one, else the org-level one.
     */
    protected function fallback_item(string $scope): ?object
    {
        $fallbackItems = \App\Models\IntegrationCollectionMappings\BaseCollectionMapping::query()
            ->where('integration_id', $this->integration->id)
            ->where('mapping_type', \App\Models\IntegrationCollectionMappings\BaseCollectionMapping::mappingTypes()['fallback_item'])
            ->get();

        if ($scope === 'billing_entity' && $this->billingEntity !== null) {
            return $fallbackItems
                ->first(fn ($mapping) => $mapping->billing_entity_id === $this->billingEntity->id);
        }

        return $fallbackItems->first(fn ($mapping) => $mapping->billing_entity_id === null);
    }

    /**
     * Rails: `lookup_collection_mapping(mapping_type, with_fallback_item:)` —
     * the billing-entity mapping wins, then (with_fallback_item) the billing
     * entity's fallback item, then the org-level mapping, then the org-level
     * fallback item.
     */
    protected function lookup_collection_mapping(string $mappingType, bool $with_fallback_item = true): ?object
    {
        $matchingMappings = \App\Models\IntegrationCollectionMappings\BaseCollectionMapping::query()
            ->where('integration_id', $this->integration->id)
            ->where('mapping_type', \App\Models\IntegrationCollectionMappings\BaseCollectionMapping::mappingTypes()[$mappingType] ?? -1)
            ->get();

        $billingEntityMapping = $matchingMappings
            ->first(fn ($mapping) => $mapping->billing_entity_id === ($this->billingEntity?->id ?? null));
        $organizationMapping = $matchingMappings
            ->first(fn ($mapping) => $mapping->billing_entity_id === null);

        if ($with_fallback_item) {
            return $billingEntityMapping
                ?? $this->fallback_item('billing_entity')
                ?? $organizationMapping
                ?? $this->fallback_item('organization');
        }

        return $billingEntityMapping ?? $organizationMapping;
    }

    /**
     * Rails: `lookup_mapping(mappable_type, mappable_id)` — the add-on /
     * billable metric mapping with the same precedence chain.
     */
    protected function lookup_mapping(string $mappableType, string|int|null $mappableId): ?object
    {
        if ($mappableId === null) {
            return null;
        }

        $matchingMappings = \App\Models\IntegrationMappings\BaseMapping::query()
            ->where('integration_id', $this->integration->id)
            ->where('mappable_type', $mappableType)
            ->where('mappable_id', $mappableId)
            ->get();

        $billingEntityMapping = $matchingMappings
            ->first(fn ($mapping) => $mapping->billing_entity_id === ($this->billingEntity?->id ?? null));
        $organizationMapping = $matchingMappings
            ->first(fn ($mapping) => $mapping->billing_entity_id === null);

        return $billingEntityMapping
            ?? $this->fallback_item('billing_entity')
            ?? $organizationMapping
            ?? $this->fallback_item('organization');
    }

    protected function formatted_date(?string $date): ?string
    {
        return $date !== null ? \Illuminate\Support\Carbon::parse($date)->toDateString() : null;
    }
}
