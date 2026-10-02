<?php

namespace App\Models;

use App\Enums\DocumentNumbering;
use App\Services\Validators\Countries;
use App\Services\Validators\Currencies;
use App\Services\Validators\EmailSanitizer;
use App\Services\Validators\Timezones;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Port of Rails' Organization model onto the frozen `organizations` table.
 * Enum column `document_numbering` is an integer column mapped by Rails'
 * enum (0=per_customer, 1=per_organization); array columns are varchar[].
 */
class Organization extends BaseModel
{
    public const EMAIL_SETTINGS = [
        'invoice.finalized',
        'credit_note.created',
        'payment_receipt.created',
    ];

    /** Rails enum order = stored value, 0-based. Never renumber. */
    public const DOCUMENT_NUMBERINGS = [
        'per_customer' => 0,
        'per_organization' => 1,
    ];

    protected $fillable = [
        'name', 'webhook_url', 'vat_rate', 'country', 'address_line1', 'address_line2',
        'state', 'zipcode', 'email', 'city', 'logo', 'legal_name', 'legal_number',
        'invoice_footer', 'invoice_grace_period', 'timezone', 'document_locale',
        'email_settings', 'tax_identification_number', 'net_payment_term',
        'default_currency', 'document_numbering', 'document_number_prefix',
        'eu_tax_management', 'premium_integrations', 'custom_aggregation',
        'finalize_zero_amount_invoice', 'audit_logs_period',
    ];

    protected $casts = [
        'vat_rate' => 'float',
        'invoice_grace_period' => 'integer',
        'net_payment_term' => 'integer',
        'email_settings' => 'array',
        'premium_integrations' => 'array',
        'feature_flags' => 'array',
        'authentication_methods' => 'array',
        'document_numbering' => DocumentNumbering::class,
        'eu_tax_management' => 'boolean',
        'custom_aggregation' => 'boolean',
        'finalize_zero_amount_invoice' => 'boolean',
        'audit_logs_period' => 'integer',
        'max_wallets' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    // -- Lifecycle (port of before_create :set_hmac_key / after_create
    // :generate_document_number_prefix) ----------------------------------

    protected static function booted(): void
    {
        static::creating(function (self $organization): void {
            if ($organization->hmac_key === null) {
                do {
                    $organization->hmac_key = (string) \Illuminate\Support\Str::uuid();
                } while (static::where('hmac_key', $organization->hmac_key)->exists());
            }

            if ($organization->slug === null) {
                $organization->slug = $organization->generateSlug();
            }
        });

        static::created(function (self $organization): void {
            $organization->generateDocumentNumberPrefix();
        });
    }

    /**
     * Port of Organizations::Sluggable#generate_slug — parameterized name,
     * reserved-word guard, uniqueness suffixing.
     */
    public const SLUG_FORMAT = '/\A[a-z0-9]([a-z0-9-]*[a-z0-9])?\z/';

    public const RESERVED_SLUGS = [
        'auth', 'login', 'sign-up', 'forgot-password', 'reset-password', 'invitation',
        'customer-portal', '404', 'forbidden', 'api', 'admin', 'graphql', 'webhooks',
        'google', 'okta', 'entra', 'settings', 'new', 'design-system', 'devtool',
        'customers', 'customer', 'plans', 'plan', 'invoices', 'invoice', 'subscriptions',
        'coupons', 'coupon', 'add-ons', 'add-on', 'billable-metrics', 'billable-metric',
        'credit-notes', 'analytics', 'analytics-v2', 'forecasts', 'payments', 'payment',
        'features', 'feature', 'tax', 'webhook', 'api-keys', 'create', 'update', 'duplicate',
    ];

    protected function generateSlug(): string
    {
        $candidate = \Illuminate\Support\Str::slug((string) $this->name, '-', 'en');

        $candidate = substr($candidate, 0, 40);
        $candidate = preg_replace('/\A-+|-+\z/', '', $candidate) ?? '';

        $generateRandom = function () {
            do {
                $candidate = 'org-' . strtolower(\Illuminate\Support\Str::random(5));
            } while (static::where('slug', $candidate)->exists());

            return $candidate;
        };

        if (strlen($candidate) < 3 || ctype_digit($candidate) || in_array($candidate, self::RESERVED_SLUGS, true)) {
            return $generateRandom();
        }

        $base = rtrim(substr($candidate, 0, 36), '-');
        while (static::where('slug', $candidate)->exists()) {
            $candidate = $base . '-' . strtolower(\Illuminate\Support\Str::random(3));
        }

        return $candidate;
    }

    public function generateDocumentNumberPrefix(): void
    {
        $this->forceFill([
            'document_number_prefix' => strtoupper(substr($this->name, 0, 3)) . '-' . strtoupper(substr($this->id, -4)),
        ])->save();
    }

    // -- Relationships (M1 subset) ----------------------------------------

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function billingEntities(): HasMany
    {
        return $this->hasMany(BillingEntity::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    public function billableMetrics(): HasMany
    {
        return $this->hasMany(BillableMetric::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(Tax::class);
    }

    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }
}
