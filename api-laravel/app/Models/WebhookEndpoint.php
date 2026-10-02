<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\PostgresArray;
use App\Enums\WebhookEndpointSignatureAlgo;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `webhook_endpoints`.
 *
 * Port of Rails' WebhookEndpoint (app/models/webhook_endpoint.rb). The
 * validations (url format with private-address blocking, uniqueness scope,
 * 10-endpoint limit, event_types whitelist) live with the CRUD controller
 * service — a later slice.
 */
#[Fillable([
    'organization_id',
    'webhook_url',
    'signature_algo',
    'event_types',
    'name',
])]
#[Table(name: 'webhook_endpoints')]
class WebhookEndpoint extends BaseModel
{
    use HasFactory;

    /** Rails: LIMIT = 10 (app/models/webhook_endpoint.rb). */
    public const int LIMIT = 10;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    /**
     * Rails: `signature_algo` enum suffix helper (`signature_algo&.to_sym`).
     * Returns the Rails enum name ('jwt' | 'hmac') or null.
     */
    public function signatureAlgoValue(): ?string
    {
        $raw = $this->getRawOriginal('signature_algo');

        return $raw === null ? null : WebhookEndpointSignatureAlgo::from((int) $raw)->label();
    }

    /**
     * Rails: `subscribed?` (app/services/webhooks/base_service.rb) — a nil
     * event_types list receives every webhook; otherwise the endpoint only
     * receives the listed types. An empty list receives nothing.
     */
    public function subscribed(string $webhookType): bool
    {
        $eventTypes = $this->event_types;

        if ($eventTypes === null) {
            return true;
        }

        return in_array($webhookType, $eventTypes, true);
    }

    protected function casts(): array
    {
        return [
            'signature_algo' => 'integer',
            'event_types' => PostgresArray::class,
        ];
    }
}
