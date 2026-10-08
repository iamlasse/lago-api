<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `record_deletions`.
 *
 * Port of Rails' RecordDeletion (app/models/record_deletion.rb).
 *
 * Written only by the `record_deletion()` trigger on TRACKED_TABLES, never
 * by the application.
 */
#[Fillable([
    'organization_id',
    'record_table',
    'record_id',
    'deleted_at',
])]
#[Table(name: 'record_deletions')]
class RecordDeletion extends BaseModel
{
    use HasFactory;

    /** Rails: TRACKED_TABLES — the tables whose deletions the trigger records. */
    public const array TRACKED_TABLES = ['fees', 'fees_taxes', 'invoice_subscriptions', 'invoices_taxes', 'credit_notes_taxes'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }
}
