<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\GraphQL\Support\TimezoneWire;
use App\Models\Customer as CustomerModel;

/**
 * Field resolvers for the frozen SDL's `Customer` type (port of Rails'
 * Types::Customers::Object computed fields). Plain columns resolve through
 * Lighthouse's default snake_case attribute lookup.
 */
class Customer
{
    /**
     * Rails: Customer#display_name — legal_name or name, then
     * "– firstname lastname" when either is present.
     */
    public function displayName(CustomerModel $root): string
    {
        $names = [];

        $legalName = $root->legal_name;
        $name = $root->name;

        if (($legalName !== null && $legalName !== '') || ($name !== null && $name !== '')) {
            $names[] = ($legalName !== null && $legalName !== '') ? $legalName : $name;
        }

        if (($root->firstname !== null && $root->firstname !== '') || ($root->lastname !== null && $root->lastname !== '')) {
            if ($names !== []) {
                $names[] = '-';
            }

            $names[] = $root->firstname;
            $names[] = $root->lastname;
        }

        return implode(' ', array_filter($names, static fn ($part): bool => $part !== null && $part !== ''));
    }

    /** Rails: Customer#applicable_timezone. */
    public function applicableTimezone(CustomerModel $root): string
    {
        return TimezoneWire::toWire($root->applicableTimezone());
    }

    /** Rails: Customer#timezone through the TimezoneEnum wire values. */
    public function timezone(CustomerModel $root): ?string
    {
        return TimezoneWire::toWire($root->timezone);
    }

    /**
     * Rails: Types::Customers::BillingConfiguration — a derived view of the
     * customer's invoice-issuing columns, with the synthetic
     * `{id}-c0nf` identifier.
     *
     * @return array{id: string, document_locale: ?string, subscription_invoice_issuing_date_anchor: ?string, subscription_invoice_issuing_date_adjustment: ?string}
     */
    public function billingConfiguration(CustomerModel $root): array
    {
        return [
            'id' => $root->id.'-c0nf',
            'document_locale' => $root->document_locale,
            'subscription_invoice_issuing_date_anchor' => $root->applicableSubscriptionInvoiceIssuingDateAnchor(),
            'subscription_invoice_issuing_date_adjustment' => $root->applicableSubscriptionInvoiceIssuingDateAdjustment(),
        ];
    }

    /**
     * Rails: `shipping_address` — the model's six shipping columns as a hash
     * (the CustomerAddress object type's fields resolve through the snake_case
     * attribute fallback).
     *
     * @return array{address_line1: ?string, address_line2: ?string, city: ?string, zipcode: ?string, state: ?string, country: ?string}|null
     */
    public function shippingAddress(CustomerModel $root): ?array
    {
        return $root->shippingAddress();
    }

    /** Rails: active_subscriptions_count — subscriptions with status 1. */
    public function activeSubscriptionsCount(CustomerModel $root): int
    {
        return $root->subscriptions()->where('status', 1)->count();
    }

    /**
     * Rails: can_edit_attributes, method: :editable?
     */
    public function canEditAttributes(CustomerModel $root): bool
    {
        return $root->editable();
    }

    /** Rails: Types::Customers::Object#applied_dunning_campaign — the relation. */
    public function appliedDunningCampaign(CustomerModel $root): ?\App\Models\DunningCampaign
    {
        return $root->appliedDunningCampaign;
    }
}
