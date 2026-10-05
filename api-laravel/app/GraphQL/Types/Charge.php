<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Charge as ChargeRecord;
use App\Enums\ChargeModel as ChargeModelEnum;

/**
 * Field resolvers for the frozen SDL's `Charge` type (port of Rails'
 * Types::Charges::Object — app/graphql/types/charges/object.rb). Plain
 * columns (code, invoiceDisplayName, invoiceable, minAmountCents,
 * payInAdvance, prorated, createdAt, updatedAt, deletedAt, parentId) and the
 * plain relations (taxes, filters) resolve through Lighthouse's default
 * snake_case attribute/relation lookup.
 *
 * billableMetric resolves through the default relation too — the port's
 * Charge#billableMetric() already loads discarded metrics (`withTrashed`,
 * Rails charges/object.rb:37-41).
 *
 * TODO(port): appliedPricingUnit — the AppliedPricingUnits feature is not
 * ported (Services\Plans\CreateService accepts and ignores the arg), so the
 * field keeps the null fallback.
 */
class Charge
{
    /**
     * Rails: pay_in_advance. The type method is required: the model's
     * payInAdvance() boolean helper would otherwise be picked up by the
     * attribute fallback as a Laravel "relation" method and crash
     * (getRelationshipFromMethod on a non-relation return).
     */
    public function payInAdvance(ChargeRecord $root): bool
    {
        return (bool) $root->pay_in_advance;
    }

    /**
     * Rails: Types::Charges::ChargeModelEnum — the enum NAME ("standard",
     * …); the column stores the integer position (App\Enums\ChargeModel).
     */
    public function chargeModel(ChargeRecord $root): ?string
    {
        $raw = $root->charge_model;

        return $raw === null ? null : ChargeModelEnum::tryFrom((int) $raw)?->label();
    }

    /**
     * Rails: Types::Charges::RegroupPaidFeesEnum — the enum NAME ("invoice");
     * the column stores the integer position (Charge::REGROUPING_PAID_FEES_OPTIONS).
     */
    public function regroupPaidFees(ChargeRecord $root): ?string
    {
        $raw = $root->regroup_paid_fees;

        if ($raw === null) {
            return null;
        }

        return ChargeRecord::REGROUPING_PAID_FEES_OPTIONS[(int) $raw] ?? null;
    }

    /**
     * Rails charges/object.rb:31-35 — the properties hash, with the legacy
     * jsonb `"{}"` default decoded to `{}` (the port's JsonbProperties cast
     * reads that default back as the raw string, Rails parity).
     *
     * @return array<string, mixed>|null
     */
    public function properties(ChargeRecord $root): ?array
    {
        $value = $root->properties;

        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }

        return $value;
    }
}
