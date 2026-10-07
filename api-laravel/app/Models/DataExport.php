<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DataExportFormat;
use App\Enums\DataExportStatus;
use App\Models\Casts\PostgresArray;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Frozen-schema model for `data_exports` (Rails' DataExport — a member's
 * CSV export request over the GraphQL surface, processed as batched
 * DataExportParts that are combined into one file attachment).
 *
 * `format` / `status` are integer columns (Rails `enum` positions,
 * DataExportFormat / DataExportStatus).
 */
#[Fillable([
    'format',
    'resource_type',
    'resource_query',
    'status',
    'expires_at',
    'started_at',
    'completed_at',
    'membership_id',
    'organization_id',
])]
#[Table(name: 'data_exports')]
class DataExport extends BaseModel
{
    /** Rails: EXPORT_FORMATS. */
    public const array EXPORT_FORMATS = ['csv'];

    /** Rails: STATUSES. */
    public const array STATUSES = ['pending', 'processing', 'completed', 'failed'];

    /** Rails: EXPIRATION_PERIOD = 7.days. */
    public const int EXPIRATION_DAYS = 7;

    protected $attributes = [
        'status' => 0,
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /** Rails: has_one :user, through: :membership. */
    public function user(): ?User
    {
        return $this->membership?->user;
    }

    public function dataExportParts(): HasMany
    {
        return $this->hasMany(DataExportPart::class);
    }

    public function formatEnum(): ?DataExportFormat
    {
        return $this->format === null
            ? null
            : (is_int($this->format) ? DataExportFormat::tryFrom($this->format) : null);
    }

    public function statusEnum(): ?DataExportStatus
    {
        if ($this->status === null) {
            return null;
        }

        $value = is_int($this->status) ? $this->status : DataExportStatus::fromOption($this->status);

        return $value === null ? null : DataExportStatus::tryFrom($value);
    }

    /** Rails: `processing!` — update!(status: "processing", started_at: now). */
    public function markProcessing(): void
    {
        $this->status = DataExportStatus::Processing->value;
        $this->started_at = now();
        $this->save();
    }

    /** Rails: `completed!` — status completed + completed_at + expires_at. */
    public function markCompleted(): void
    {
        $this->status = DataExportStatus::Completed->value;
        $this->completed_at = now();
        $this->expires_at = now()->addDays(self::EXPIRATION_DAYS);
        $this->save();
    }

    /** Rails: `failed!`. */
    public function markFailed(): void
    {
        $this->status = DataExportStatus::Failed->value;
        $this->save();
    }

    /** Rails: `expired?`. */
    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return Carbon::instance($this->expires_at)->isPast();
    }

    /** Rails: `completed?`. */
    public function isCompleted(): bool
    {
        return $this->statusEnum() === DataExportStatus::Completed;
    }

    /** Rails: `pending?`. */
    public function isPending(): bool
    {
        return $this->statusEnum() === DataExportStatus::Pending;
    }

    /** Rails: `filename` — "YYYYMMDDHHMMSS_<resource_type>.<format>". */
    public function filename(): string
    {
        $format = $this->formatEnum()?->label() ?? 'csv';

        return $this->created_at->format('YmdHis').'_'.$this->resource_type.'.'.$format;
    }

    /**
     * Rails: `file_url` — File.join(ENV["LAGO_API_URL"],
     * rails_blob_path(file, host: "void", expires_in: EXPIRATION_PERIOD)).
     * The ActiveStorage port serves the raw blob id at the same redirect
     * path (see App\Support\ActiveStorage — the signed-blob-id expiry is a
     * documented deviation).
     */
    public function fileUrl(): ?string
    {
        $blob = ActiveStorage::blob($this, 'file');

        return ActiveStorage::url($blob);
    }

    /**
     * Rails: `export_class` — the CSV service per resource type (null when
     * the resource type is not exportable).
     */
    public function exportClass(): ?string
    {
        return match ($this->resource_type) {
            'credit_notes' => \App\Services\DataExports\Csv\CreditNotes::class,
            'credit_note_items' => \App\Services\DataExports\Csv\CreditNoteItems::class,
            'invoices' => \App\Services\DataExports\Csv\Invoices::class,
            'invoice_fees' => \App\Services\DataExports\Csv\InvoiceFees::class,
            default => null,
        };
    }

    /** Rails: SecureRandom.hex(5) key component of the attachment. */
    public static function hex5(): string
    {
        return bin2hex(random_bytes(5));
    }

    protected function casts(): array
    {
        return [
            'format' => 'integer',
            'status' => 'integer',
            'resource_query' => 'array',
            'expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
