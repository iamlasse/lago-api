<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `inbound_webhooks` (Rails' InboundWebhook — the
 * raw, verified webhook payload persisted by
 * InboundWebhooks::CreateService before async processing).
 *
 * `source` holds the webhook source slug ("stripe", "moneyhash", ...);
 * `status` is the `inbound_webhook_status` pg enum (pending default).
 */
#[Fillable([
    'source',
    'event_type',
    'payload',
    'status',
    'organization_id',
    'code',
    'signature',
    'processing_at',
])]
#[Table(name: 'inbound_webhooks')]
class InboundWebhook extends BaseModel
{
    use HasFactory;

    /** Rails: InboundWebhook::WEBHOOK_PROCESSING_WINDOW (2.hours). */
    public const WEBHOOK_PROCESSING_WINDOW_MINUTES = 120;

    /** Rails: InboundWebhook::STATUSES (pg enum inbound_webhook_status). */
    public const STATUSES = ['pending', 'processing', 'succeeded', 'failed'];

    /** Rails: default 'pending'. */
    protected $attributes = [
        'status' => 'pending',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /** Rails: `inbound_webhook.processing!` — enter the window. */
    public function markProcessing(): void
    {
        $this->update(['status' => 'processing', 'processing_at' => now()]);
    }

    /** Rails: `inbound_webhook.failed!`. */
    public function markFailed(): void
    {
        $this->update(['status' => 'failed']);
    }

    /** Rails: `inbound_webhook.succeeded!`. */
    public function markSucceeded(): void
    {
        $this->update(['status' => 'succeeded']);
    }

    /** @return array<string, mixed> */
    public function payloadJson(): array
    {
        $payload = $this->payload;

        if (is_string($payload)) {
            return json_decode($payload, true) ?? [];
        }

        return is_array($payload) ? $payload : [];
    }

    // -- Retry/recovery slice (appended) -------------------------------------

    /** Rails: `scope :reprocessable` — processing past the 2h window. */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function reprocessable($query)
    {
        return $query->where('status', 'processing')
            ->where('processing_at', '<=', now()->subMinutes(self::WEBHOOK_PROCESSING_WINDOW_MINUTES));
    }

    /** Rails: `scope :old_pending` — pending created past the 2h window. */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function oldPending($query)
    {
        return $query->where('status', 'pending')
            ->where('created_at', '<=', now()->subMinutes(self::WEBHOOK_PROCESSING_WINDOW_MINUTES));
    }

    /** Rails: `scope :retriable` — reprocessable.or(old_pending). */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function retriable($query)
    {
        return $query->reprocessable()->orWhere(fn ($q) => $q->oldPending());
    }

    protected function casts(): array
    {
        return [
            // jsonb column holding the raw request body.
            'payload' => 'string',
            // processing_at is a plain timestamp column — cast so the retry
            // window comparisons get a Carbon instance.
            'processing_at' => 'datetime',
        ];
    }
}
