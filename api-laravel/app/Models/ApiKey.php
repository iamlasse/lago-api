<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `api_keys`. Refined with the Rails ApiKey model's
 * permission defaults, value generation and relations.
 */
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'organization_id',
    'value',
    'expires_at',
    'last_used_at',
    'name',
    'permissions',
])]
#[\Illuminate\Database\Eloquent\Attributes\Table(name: 'api_keys')]
class ApiKey extends BaseModel
{
    use HasFactory;

    /** Rails: ApiKey::RESOURCES — permission subjects. */
    public const RESOURCES = [
        'activity_log', 'add_on', 'analytic', 'api_log', 'billable_metric', 'coupon', 'applied_coupon', 'credit_note',
        'customer_usage', 'customer', 'event', 'fee', 'invoice', 'organization', 'order', 'order_form', 'payment',
        'payment_receipt', 'payment_request', 'payment_method', 'plan', 'subscription', 'lifetime_usage', 'tax',
        'wallet', 'wallet_transaction', 'webhook_endpoint', 'webhook_jwt_public_key', 'invoice_custom_section',
        'billing_entity', 'alert', 'feature', 'security_log', 'quote', 'product_category', 'product', 'rate_card',
        'plan_rate_card', 'contract', 'contract_rate_card', 'usage_attribution_type', 'x402_connection', 'x402',
    ];

    /** Rails: ApiKey::MODES. */
    public const MODES = ['read', 'write'];

    /**
     * Rails: `ApiKey.default_permissions` — every resource allows both modes.
     *
     * @return array<string, list<string>>
     */
    public static function defaultPermissions(): array
    {
        $permissions = [];

        foreach (self::RESOURCES as $resource) {
            $permissions[$resource] = self::MODES;
        }

        return $permissions;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Rails: `expired?`. */
    public function expired(?CarbonInterface $time = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt($time ?? now());
    }

    /**
     * Rails: `flat_permissions` — "resource:mode" strings, sorted.
     *
     * @return list<string>
     */
    public function flatPermissions(): array
    {
        $flat = [];

        foreach ((array) ($this->permissions ?? []) as $resource => $modes) {
            foreach ((array) $modes as $mode) {
                $flat[] = $resource.':'.$mode;
            }
        }

        sort($flat);

        return $flat;
    }

    // -- Lifecycle ------------------------------------------------------------

    protected static function booted(): void
    {
        // Rails: `attribute :permissions, default: -> { default_permissions }`
        //        + `before_create :set_value`
        static::creating(function (self $apiKey): void {
            if ($apiKey->permissions === null) {
                $apiKey->permissions = self::defaultPermissions();
            }

            if ($apiKey->value === null) {
                do {
                    $apiKey->value = (string) Str::uuid();
                } while (static::query()->where('value', $apiKey->value)->exists());
            }
        });
    }

    /** Rails: `scope :active` — non-expired keys only. */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function active($query)
    {
        return $query->where(function ($query): void {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'permissions' => 'array',
        ];
    }
}
