<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frozen-schema model for `recurring_transaction_rules_invoice_custom_sections` — Rails'
 * RecurringTransactionRule::AppliedInvoiceCustomSection
 * (app/models/recurringtransactionrule/applied_invoice_custom_section.rb):
 * a custom section selected for one recurring_transaction_rule.
 */
#[Fillable([
    'organization_id',
    'recurring_transaction_rule_id',
    'invoice_custom_section_id',
])]
#[Table(name: 'recurring_transaction_rules_invoice_custom_sections')]
class RecurringTransactionRuleAppliedInvoiceCustomSection extends BaseModel
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [

        ];
    }

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :recurring_transaction_rule`. */
    public function recurring_transaction_rule(): BelongsTo
    {
        return $this->belongsTo(RecurringTransactionRule::class);
    }

    /** Rails: `belongs_to :invoice_custom_section`. */
    public function invoiceCustomSection(): BelongsTo
    {
        return $this->belongsTo(InvoiceCustomSection::class);
    }
}

