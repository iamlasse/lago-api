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

    // -- Attribute behavior (ports of Rails readers/writers/normalizers) ----

    protected function email(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::set(
            fn (?string $value) => EmailSanitizer::sanitize($value),
        );
    }

    /** Rails: `def document_number_prefix=(value); super(value&.upcase); end` */
    protected function documentNumberPrefix(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::set(
            fn (?string $value) => $value === null ? null : strtoupper($value),
        );
    }

    /**
     * Rails: the organization delegates `default_currency` to its default
     * billing entity when one exists (migration of this data to billing
     * entities) — falls back to the column.
     */
    protected function defaultCurrency(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::get(
            function (?string $value) {
                $billingEntity = $this->defaultBillingEntity;

                return $billingEntity?->default_currency ?? $value;
            },
        );
    }

    /** Same delegation as default_currency. */
    protected function timezone(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::get(
            function (?string $value) {
                $billingEntity = $this->defaultBillingEntity;

                return $billingEntity?->timezone ?? $value;
            },
        );
    }

    /** Port of Organization#events_store. */
    public function eventsStore(): string
    {
        return $this->clickhouse_events_store ? 'clickhouse' : 'postgres';
    }

    /** Port of Organization#eu_vat_eligible?. */
    public function euVatEligible(): bool
    {
        return $this->country !== null
            && in_array($this->country, EuVatRates::countryCodes(), true);
    }

    /**
     * Port of the Rails model validations (organizations run on every
     * `save!`) — returns `field => [api error codes]`, empty when valid.
     * The `on: :update` rules apply only when the model exists.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        if ($this->country !== null && ! Countries::valid($this->country)) {
            $errors['country'] = ['not_a_valid_country_code'];
        }

        if (! Currencies::valid($this->getRawOriginal('default_currency'))) {
            $errors['default_currency'] = ['value_is_invalid'];
        }

        if ($this->document_locale !== null && ! $this->validLocale($this->document_locale)) {
            $errors['document_locale'] = ['not_a_valid_language_code'];
        }

        if ($this->email !== null && $this->email !== '' && ! EmailSanitizer::valid($this->email)) {
            $errors['email'] = ['invalid_email_format'];
        }

        if ($this->invoice_footer !== null && mb_strlen($this->invoice_footer) > 600) {
            $errors['invoice_footer'] = ['value_is_too_long'];
        }

        if ($this->exists && $this->document_number_prefix !== null
            && (strlen($this->document_number_prefix) < 1 || strlen($this->document_number_prefix) > 10)) {
            $errors['document_number_prefix'] = [strlen($this->document_number_prefix) > 10 ? 'value_is_too_long' : 'value_is_too_short'];
        }

        foreach (['invoice_grace_period' => $this->invoice_grace_period, 'net_payment_term' => $this->net_payment_term] as $field => $value) {
            if ($value !== null && $value < 0) {
                $errors[$field] = ['value_is_out_of_range'];
            }
        }

        if (($this->name ?? '') === '') {
            $errors['name'] = ['value_is_mandatory'];
        }

        if ($this->getRawOriginal('timezone') !== null && ! Timezones::valid($this->getRawOriginal('timezone'))) {
            $errors['timezone'] = ['invalid_timezone'];
        }

        foreach ((array) ($this->email_settings ?? []) as $setting) {
            if (! in_array($setting, self::EMAIL_SETTINGS, true)) {
                $errors['email_settings'] = ['unsupported_value'];

                break;
            }
        }

        $documentNumbering = $this->getRawOriginal('document_numbering');
        if ($documentNumbering !== null && ! in_array((int) $documentNumbering, [0, 1], true)) {
            $errors['document_numbering'] = ['value_is_invalid'];
        }

        $slugErrors = $this->validateSlug();

        if ($slugErrors !== []) {
            $errors['slug'] = $slugErrors;
        }

        return $errors;
    }

    /**
     * Port of Organizations::Sluggable validations — run on create or when
     * the slug changed.
     *
     * @return list<string>
     */
    protected function validateSlug(): array
    {
        if (! ($this->exists === false || $this->isDirty('slug'))) {
            return [];
        }

        $slug = $this->slug;

        if ($slug === null || $slug === '') {
            return ['value_is_mandatory'];
        }

        $errors = [];

        if (strlen($slug) < 3) {
            $errors[] = 'value_is_too_short';
        }

        if (strlen($slug) > 40) {
            $errors[] = 'value_is_too_long';
        }

        if (preg_match(self::SLUG_FORMAT, $slug) !== 1) {
            $errors[] = 'value_is_invalid';
        }

        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            $errors[] = 'value_is_reserved';
        }

        if ($errors === []) {
            $taken = static::query()->where('slug', $slug);

            if ($this->exists) {
                $taken->where($this->getKeyName(), '!=', $this->getKey());
            }

            if ($taken->exists()) {
                $errors[] = 'value_already_exist';
            }
        }

        return array_values(array_unique($errors));
    }

    /** Port of the LanguageCodeValidator — Rails' I18n.available_locales. */
    protected function validLocale(string $locale): bool
    {
        return in_array($locale, self::AVAILABLE_LOCALES, true);
    }

    /** Rails: `config.i18n.available_locales`. */
    public const AVAILABLE_LOCALES = ['en', 'fr', 'nb', 'de', 'it', 'es', 'sv', 'pt-BR', 'zh-TW'];

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

    /** Rails: `has_many :active_memberships, -> { active }`. */
    public function activeMemberships(): HasMany
    {
        return $this->hasMany(Membership::class)->where('status', MembershipStatus::Active->value);
    }

    public function users(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships', 'organization_id', 'user_id');
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /** Rails: `has_many :billing_entities, -> { active }`. */
    public function billingEntities(): HasMany
    {
        return $this->hasMany(BillingEntity::class)
            ->whereNull('archived_at')
            ->oldest('created_at');
    }

    /** Rails: `has_many :all_billing_entities` (no active scope). */
    public function allBillingEntities(): HasMany
    {
        return $this->hasMany(BillingEntity::class);
    }

    /** Rails: `has_one :default_billing_entity, -> { active.order(created_at: :asc) }`. */
    public function defaultBillingEntity(): HasOne
    {
        return $this->hasOne(BillingEntity::class)
            ->whereNull('archived_at')
            ->oldest('created_at');
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
