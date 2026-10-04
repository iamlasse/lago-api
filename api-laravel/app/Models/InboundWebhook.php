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

    /** Rails: default 'pending'. */
    protected $attributes = [
        'status' => 'pending',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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

    protected function casts(): array
    {
        return [
            // jsonb column holding the raw request body.
            'payload' => 'string',
        ];
    }
}
