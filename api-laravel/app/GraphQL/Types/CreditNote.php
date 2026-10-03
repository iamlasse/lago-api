<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\CreditNote as CreditNoteModel;

/**
 * Field resolvers for the frozen SDL's `CreditNote` type (port of Rails'
 * Types::CreditNotes::Object computed fields). Plain columns resolve through
 * the snake_case attribute fallback; unported features (activity logs,
 * error details, metadata, integration sync, file/xml attachments) keep the
 * null/false fallback documented in graphql/FULL_SCHEMA_NOTES.md.
 */
class CreditNote
{
    /** Rails: the reason enum name — the column stores the integer position. */
    public function reason(CreditNoteModel $root): ?string
    {
        return $root->reasonEnum()?->label();
    }

    /** Rails: the credit_status enum name — the column stores the integer position. */
    public function creditStatus(CreditNoteModel $root): ?string
    {
        return $root->creditStatusEnum()?->label();
    }

    /** Rails: the refund_status enum name — the column stores the integer position. */
    public function refundStatus(CreditNoteModel $root): ?string
    {
        return $root->refundStatusEnum()?->label();
    }

    /** Rails: currency — total_amount_currency. */
    public function currency(CreditNoteModel $root): ?string
    {
        $currency = $root->currency();

        return $currency !== '' ? $currency : null;
    }

    /** Rails: can_be_voided? — CreditNote#voidable?. */
    public function canBeVoided(CreditNoteModel $root): bool
    {
        return $root->voidable();
    }

    /** Rails: sub_total_excluding_taxes_amount_cents. */
    public function subTotalExcludingTaxesAmountCents(CreditNoteModel $root): int
    {
        return $root->subTotalExcludingTaxesAmountCents();
    }

    /** Rails: applied_taxes — tax_rate DESC (the unpersisted sort in Rails). */
    public function appliedTaxes(CreditNoteModel $root): \Illuminate\Support\Collection
    {
        if ($root->relationLoaded('appliedTaxes')) {
            return $root->appliedTaxes->sortByDesc('tax_rate')->values();
        }

        return $root->appliedTaxes()->orderByDesc('tax_rate')->get();
    }

    // -- Unported features (stubs, see FULL_SCHEMA_NOTES.md) -------------------

    public function activityLogs(CreditNoteModel $root): ?array
    {
        return null;
    }

    public function errorDetails(CreditNoteModel $root): ?array
    {
        return null;
    }

    public function metadata(CreditNoteModel $root): ?array
    {
        return null;
    }

    public function externalIntegrationId(CreditNoteModel $root): ?string
    {
        return null;
    }

    public function integrationSyncable(CreditNoteModel $root): bool
    {
        return false;
    }

    public function taxProviderId(CreditNoteModel $root): ?string
    {
        return null;
    }

    public function taxProviderSyncable(CreditNoteModel $root): bool
    {
        return false;
    }

    public function fileUrl(CreditNoteModel $root): ?string
    {
        return $root->fileUrl();
    }

    public function xmlUrl(CreditNoteModel $root): ?string
    {
        return $root->xmlUrl();
    }
}
