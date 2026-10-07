<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\PostgresArray;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Frozen-schema model for `data_export_parts` (Rails' DataExportPart — one
 * batch of an export: the batched object ids and, once processed, the
 * serialized CSV lines for that batch).
 */
#[Fillable([
    'index',
    'data_export_id',
    'object_ids',
    'completed',
    'csv_lines',
    'organization_id',
])]
#[Table(name: 'data_export_parts')]
class DataExportPart extends BaseModel
{
    protected $attributes = [
        'completed' => false,
    ];

    public function dataExport(): BelongsTo
    {
        return $this->belongsTo(DataExport::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Rails: `scope :completed`. */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('completed', true);
    }

    protected function casts(): array
    {
        return [
            'index' => 'integer',
            'completed' => 'boolean',
            // uuid[] column — the PostgresArray cast speaks the {"a","b"}
            // literal format (Laravel's `array` cast would JSON-encode).
            'object_ids' => PostgresArray::class,
        ];
    }
}
