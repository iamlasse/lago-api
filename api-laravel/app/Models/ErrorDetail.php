<?php

declare(strict_types=1);

namespace App\Models;

use Throwable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `error_details` (Rails' ErrorDetail — the
 * structured failure record attached to any owner (invoice, credit note)
 * whose generation/processing hit a provider error).
 *
 * Rails uses Discard on deleted_at with `default_scope -> { kept }`; the
 * Laravel port is SoftDeletes on the same column (kept = whereNull).
 *
 * `error_code` is the Rails integer enum (0 not_provided, 1 tax_error,
 * 2 tax_voiding_error, 3 invoice_generation_error).
 */
#[Fillable([
    'organization_id',
    'owner_type',
    'owner_id',
    'error_code',
    'details',
])]
#[Table(name: 'error_details')]
class ErrorDetail extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    /** Rails: ErrorDetail::ERROR_CODES (integer enum order — never renumber). */
    public const ERROR_CODES = [
        'not_provided' => 0,
        'tax_error' => 1,
        'tax_voiding_error' => 2,
        'invoice_generation_error' => 3,
    ];

    /**
     * Port of `ErrorDetail.create_generation_error_for(invoice:, error:)`
     * (app/models/error_detail.rb) — upsert of the invoice-generation
     * failure record. Idempotent per (owner, error_code): a second call
     * for the same invoice refreshes the details, keeping the same row.
     *
     * Rails stores `error.inspect.to_json` (the inspect STRING, JSON-encoded)
     * plus the serialized invoice (minus file blobs) and its subscriptions.
     *
     * @return $this|null null mirrors Rails' bare `return` for a nil invoice.
     */
    public static function createGenerationErrorFor(?Invoice $invoice, Throwable $error): ?self
    {
        if ($invoice === null) {
            return null;
        }

        $instance = static::query()->firstOrCreate([
            'owner_type' => $invoice->railsName(),
            'owner_id' => $invoice->id,
            'error_code' => self::ERROR_CODES['invoice_generation_error'],
            'organization_id' => $invoice->organization_id,
        ]);

        $instance->details = [
            'backtrace' => $error->getTrace(),
            'error' => json_encode('#<'.get_class($error).': '.$error->getMessage().'>', JSON_THROW_ON_ERROR),
            'invoice' => json_encode(collect($invoice->getAttributes())->except(['file', 'xml_file'])->all(), JSON_THROW_ON_ERROR),
            'subscriptions' => $invoice->subscriptions()->get()->map(
                fn (Subscription $subscription) => collect($subscription->getAttributes())->all(),
            )->toJson(),
        ];
        $instance->save();

        return $instance;
    }

    public function owner(): MorphTo
    {
        // owner_type stores Rails class names ("Invoice"/"CreditNote") —
        // resolved via the global morph map in AppServiceProvider.
        return $this->morphTo();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected function casts(): array
    {
        return [
            'error_code' => 'integer',
            'details' => 'array',
        ];
    }
}
