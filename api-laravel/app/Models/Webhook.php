<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WebhookStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `webhooks`.
 *
 * Port of Rails' Webhook (app/models/webhook.rb).
 *
 * Rails stores payload/response in object storage (ActiveStorage blob under
 * `payload_key` / `response_key`, gzip JSON) and falls back to the database
 * `payload` / `response` json columns when the key is absent. The Laravel
 * port always uses the database columns (payload_key / response_key stay
 * null), which is Rails' legacy path — same payloads, same accessors.
 */
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'object_id',
    'object_type',
    'status',
    'retries',
    'http_status',
    'endpoint',
    'webhook_type',
    'payload',
    'response',
    'last_retried_at',
    'webhook_endpoint_id',
    'organization_id',
    'payload_key',
    'response_key',
])]
#[\Illuminate\Database\Eloquent\Attributes\Table(name: 'webhooks')]
class Webhook extends BaseModel
{
    use HasFactory;

    public function webhookEndpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // -- Rails enum suffix helpers ------------------------------------------------

    public function pending(): bool
    {
        return $this->statusValue() === WebhookStatus::Pending->value;
    }

    public function succeeded(): bool
    {
        return $this->statusValue() === WebhookStatus::Succeeded->value;
    }

    public function failed(): bool
    {
        return $this->statusValue() === WebhookStatus::Failed->value;
    }

    public function retrying(): bool
    {
        return $this->statusValue() === WebhookStatus::Retrying->value;
    }

    /** The stored integer status, whatever the assignment form was. */
    public function statusValue(): ?int
    {
        $raw = $this->getRawOriginal('status');

        return $raw === null ? null : (int) $raw;
    }

    /** Rails: `pending!` / `succeeded!` / `retrying!` / `failed!` bang writers. */
    public function writeStatus(WebhookStatus|string $status): static
    {
        $value = is_string($status) ? WebhookStatus::fromOption($status) : $status->value;
        $this->status = $value;

        return $this;
    }

    // -- Signature headers (Rails: Webhook#generate_headers) -----------------------

    /**
     * Rails: `generate_headers` — the outbound webhook signature headers.
     *
     * @return array<string, string>
     */
    public function generateHeaders(): array
    {
        $algo = $this->webhookEndpoint->signatureAlgoValue();
        $signature = match ($algo) {
            'jwt' => $this->jwtSignature(),
            'hmac' => $this->hmacSignature(),
            default => null,
        };

        return [
            'X-Lago-Signature' => $signature ?? '',
            'X-Lago-Signature-Algorithm' => $algo ?? '',
            'X-Lago-Unique-Key' => $this->id,
        ];
    }

    /**
     * Rails: `jwt_signature` — RS256 over `{data: payload.to_json, iss: issuer}`
     * with RsaPrivateKey (config/initializers/rsa_keys.rb).
     */
    public function jwtSignature(): string
    {
        return \Firebase\JWT\JWT::encode(
            [
                'data' => $this->payloadJson(),
                'iss' => $this->issuer(),
            ],
            $this->rsaPrivateKey(),
            'RS256',
        );
    }

    /**
     * Rails: `hmac_signature` — Base64(HMAC-SHA256(organization.hmac_key,
     * payload.to_json)).
     */
    public function hmacSignature(): string
    {
        $hmac = hash_hmac('sha256', $this->payloadJson(), (string) $this->organization->hmac_key, true);

        return base64_encode($hmac);
    }

    /** Rails: `issuer` — ENV["LAGO_API_URL"]. */
    public function issuer(): ?string
    {
        return config('lago.api_url');
    }

    /** The payload exactly as Rails signs it: `payload.to_json`. */
    public function payloadJson(): string
    {
        return json_encode($this->payload, JSON_UNESCAPED_SLASHES);
    }

    // -- Payload / response storage ------------------------------------------------

    /**
     * Rails: `store_payload` — object-storage upload in Rails; the Laravel
     * port keeps the payload on the row (Rails' database fallback path).
     */
    public function storePayload(array $content): static
    {
        $this->payload = $content;

        return $this;
    }

    /**
     * Rails: `store_response` — the `response` column is json and holds any
     * JSON value (an object, or a bare string for the connection-failure /
     * blocked-address messages), so content is stored JSON-encoded verbatim
     * and decoded by responseJson().
     */
    public function storeResponse(mixed $content): static
    {
        $this->response = json_encode($content, JSON_UNESCAPED_SLASHES);

        return $this;
    }

    /** Rails: `response` accessor — the decoded stored response, any type. */
    public function responseJson(): mixed
    {
        $raw = $this->getAttributes()['response'] ?? $this->getRawOriginal('response');

        if ($raw === null) {
            return null;
        }

        return json_decode(is_string($raw) ? $raw : (string) $raw, true);
    }

    protected function casts(): array
    {
        return [
            'retries' => 'integer',
            'http_status' => 'integer',
            'payload' => 'array',
            'last_retried_at' => 'datetime',
        ];
    }

    private function rsaPrivateKey(): string
    {
        $path = config('lago.webhook.rsa_private_key_path');

        if (is_string($path) && is_file($path)) {
            return (string) file_get_contents($path);
        }

        return (string) base64_decode((string) env('LAGO_RSA_PRIVATE_KEY'), true);
    }
}
