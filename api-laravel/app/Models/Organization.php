<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Str;
use App\Enums\MembershipStatus;
use App\Enums\DocumentNumbering;
use App\Models\Casts\PostgresArray;
use App\Services\Validators\Countries;
use App\Services\Validators\Timezones;
use App\Services\Validators\Currencies;
use App\Services\Validators\EuVatRates;
use App\Services\Validators\EmailSanitizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Port of Rails' Organization model onto the frozen `organizations` table.
 * Enum column `document_numbering` is an integer column mapped by Rails'
 * enum (0=per_customer, 1=per_organization); array columns are varchar[].
 */
#[Fillable([
    'name', 'webhook_url', 'vat_rate', 'country', 'address_line1', 'address_line2',
    'state', 'zipcode', 'email', 'city', 'logo', 'legal_name', 'legal_number',
    'invoice_footer', 'invoice_grace_period', 'timezone', 'document_locale',
    'email_settings', 'tax_identification_number', 'net_payment_term',
    'default_currency', 'document_numbering', 'document_number_prefix',
    'eu_tax_management', 'premium_integrations', 'custom_aggregation',
    'finalize_zero_amount_invoice', 'audit_logs_period',
])]
#[RouteKey('id')]
class Organization extends BaseModel
{
    use HasFactory;

    /** Rails: `MULTI_ENTITIES_MAX` (organization.rb:19). */
    public const MULTI_ENTITIES_MAX = [
        'default' => 1,
        'pro' => 2,
        'enterprise' => null, // Float::INFINITY — unused while the premium flags are unported.
    ];

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

    /** Rails: `config.i18n.available_locales`. */
    public const AVAILABLE_LOCALES = ['en', 'fr', 'nb', 'de', 'it', 'es', 'sv', 'pt-BR', 'zh-TW'];

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

    /**
     * Rails' ActiveRecord carries the schema's column defaults in every new
     * instance; Eloquent does not, so they are declared here. `slug`,
     * `hmac_key` and `document_number_prefix` stay unset — the model
     * generates them on create.
     */
    protected $attributes = [
        'vat_rate' => 0.0,
        'invoice_grace_period' => 0,
        'timezone' => 'UTC',
        'document_locale' => 'en',
        'email_settings' => '{}',
        'net_payment_term' => 0,
        'default_currency' => 'USD',
        'document_numbering' => 0,
        'eu_tax_management' => false,
        'premium_integrations' => '{}',
        'custom_aggregation' => false,
        'finalize_zero_amount_invoice' => true,
        'clickhouse_events_store' => false,
        'authentication_methods' => '{email_password,google_oauth}',
        'audit_logs_period' => 30,
        'feature_flags' => '{}',
    ];

    /** Port of Organization#events_store. */
    public function eventsStore(): string
    {
        return $this->clickhouse_events_store ? 'clickhouse' : 'postgres';
    }

    /** Port of Organization#clickhouse_events_store?. */
    public function clickhouseEventsStore(): bool
    {
        return (bool) $this->clickhouse_events_store;
    }

    /** Port of Organization#postgres_events_store? — !clickhouse_events_store?. */
    public function postgresEventsStore(): bool
    {
        return ! $this->clickhouseEventsStore();
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

        // Column defaults: a never-assigned attribute holds the schema's
        // default in the database — validate the default like Rails does,
        // whose models load column defaults into the attribute set.
        $defaultCurrency = $this->getRawOriginal('default_currency')
            ?? ($this->isDirty('default_currency') ? null : 'USD');

        if (! Currencies::valid($defaultCurrency)) {
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
            && (mb_strlen($this->document_number_prefix) < 1 || mb_strlen($this->document_number_prefix) > 10)) {
            $errors['document_number_prefix'] = [mb_strlen($this->document_number_prefix) > 10 ? 'value_is_too_long' : 'value_is_too_short'];
        }

        foreach (['invoice_grace_period' => $this->invoice_grace_period, 'net_payment_term' => $this->net_payment_term] as $field => $value) {
            if ($value !== null && $value < 0) {
                $errors[$field] = ['value_is_out_of_range'];
            }
        }

        if (($this->name ?? '') === '') {
            $errors['name'] = ['value_is_mandatory'];
        }

        $timezone = $this->getRawOriginal('timezone')
            ?? ($this->isDirty('timezone') ? null : 'UTC');

        if ($timezone !== null && ! Timezones::valid($timezone)) {
            $errors['timezone'] = ['invalid_timezone'];
        }

        foreach ((array) ($this->email_settings ?? []) as $setting) {
            if (! in_array($setting, self::EMAIL_SETTINGS, true)) {
                $errors['email_settings'] = ['unsupported_value'];

                break;
            }
        }

        $documentNumbering = $this->getRawOriginal('document_numbering');

        if ($documentNumbering !== null
            && (! ctype_digit((string) $documentNumbering) || ! in_array((int) $documentNumbering, [0, 1], true))) {
            $errors['document_numbering'] = ['value_is_invalid'];
        }

        $slugErrors = $this->validateSlug();

        if ($slugErrors !== []) {
            $errors['slug'] = $slugErrors;
        }

        return $errors;
    }

    public function generateDocumentNumberPrefix(): void
    {
        $this->forceFill([
            'document_number_prefix' => mb_strtoupper(mb_substr($this->name, 0, 3)).'-'.mb_strtoupper(mb_substr($this->id, -4)),
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

    public function users(): BelongsToMany
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

    /**
     * Rails: `remaining_billing_entities` — the multi-entities allowance
     * minus the active count, chosen by the premium feature flags
     * (organization.rb:328-334).
     */
    public function remainingBillingEntities(): int
    {
        if ($this->multiEntitiesEnterpriseEnabled()) {
            return PHP_INT_MAX;
        }

        $cap = $this->multiEntitiesProEnabled()
            ? self::MULTI_ENTITIES_MAX['pro']
            : self::MULTI_ENTITIES_MAX['default'];

        return $cap - $this->billingEntities()->count();
    }

    /** Rails: `can_create_billing_entity?`. */
    public function canCreateBillingEntity(): bool
    {
        return $this->remainingBillingEntities() > 0;
    }

    /**
     * Rails: `multi_entities_pro_enabled?` / `multi_entities_enterprise_enabled?`
     * (HasFeatureFlags) — the flags the license server turns on; OSS orgs
     * carry none. Dev can enable them on the organizations.feature_flags
     * varchar[] column directly.
     */
    public function multiEntitiesProEnabled(): bool
    {
        return in_array('multi_entities_pro', (array) $this->feature_flags, true);
    }

    public function multiEntitiesEnterpriseEnabled(): bool
    {
        return in_array('multi_entities_enterprise', (array) $this->feature_flags, true);
    }

    /** Rails: `has_many :all_billing_entities` (no active scope). */
    public function allBillingEntities(): HasMany
    {
        return $this->hasMany(BillingEntity::class);
    }

    /** Rails: `has_many :add_ons` (the one-off invoice payload resolves fees by code). */
    public function addOns(): HasMany
    {
        return $this->hasMany(AddOn::class);
    }

    /** Rails: `has_many :orders` (the orders slice — sequenced scope target). */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Rails: `has_many :payment_providers, class_name: "PaymentProviders::BaseProvider"`. */
    public function paymentProviders(): HasMany
    {
        return $this->hasMany(PaymentProvider::class);
    }

    /** Rails: `has_many :payment_methods`. */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
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

    // -- Product catalog (v2) relations (append-only) -------------------------

    /** Rails: `has_many :products`. */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Rails: `has_many :product_categories`. */
    public function productCategories(): HasMany
    {
        return $this->hasMany(ProductCategory::class);
    }

    /** Rails: `has_many :product_filters`. */
    public function productFilters(): HasMany
    {
        return $this->hasMany(ProductFilter::class);
    }

    /** Rails: `has_many :rate_cards`. */
    public function rateCards(): HasMany
    {
        return $this->hasMany(RateCard::class);
    }

    /** Rails: `has_many :rate_card_rates`. */
    public function rateCardRates(): HasMany
    {
        return $this->hasMany(RateCardRate::class);
    }

    /** Rails: `has_many :catalog_plans`. */
    public function catalogPlans(): HasMany
    {
        return $this->hasMany(CatalogPlan::class);
    }

    /** Rails: `has_many :contracts`. */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
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

    // -- PREMIUM_INTEGRATIONS flag helpers (appended) --------------------------
    //
    // Rails' Organization model defines `<integration>_enabled?` for every
    // PREMIUM_INTEGRATIONS entry as `License.premium? &&
    // premium_integrations.include?(name)`; the port adds the ones the
    // ported slices need.

    /** Rails: auto_dunning_enabled? (premium integration "auto_dunning"). */
    public function autoDunningEnabled(): bool
    {
        return $this->premiumIntegrationEnabled('auto_dunning');
    }

    /** Rails: issue_receipts_enabled? (premium integration "issue_receipts"). */
    public function issueReceiptsEnabled(): bool
    {
        return $this->premiumIntegrationEnabled('issue_receipts');
    }

    /** Rails: lifetime_usage_enabled? (premium integration "lifetime_usage"). */
    public function lifetimeUsageEnabled(): bool
    {
        return $this->premiumIntegrationEnabled('lifetime_usage');
    }

    /** Rails: progressive_billing_enabled? (premium integration "progressive_billing"). */
    public function progressiveBillingEnabled(): bool
    {
        return $this->premiumIntegrationEnabled('progressive_billing');
    }

    /** Rails: granular_lifetime_usage_enabled? (premium integration "granular_lifetime_usage"). */
    public function granularLifetimeUsageEnabled(): bool
    {
        return $this->premiumIntegrationEnabled('granular_lifetime_usage');
    }

    /** Rails: using_lifetime_usage? — lifetime usage OR progressive billing. */
    public function usingLifetimeUsage(): bool
    {
        return $this->lifetimeUsageEnabled() || $this->progressiveBillingEnabled();
    }

    /** Rails: has_many :integrations (Integrations::BaseIntegration). */
    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    /** Rails: avalara_enabled? (premium integration "avalara"; anrok is non-premium). */
    public function avalaraEnabled(): bool
    {
        return \App\Support\License::premium()
            && in_array('avalara', (array) ($this->premium_integrations ?? []), true);
    }

    /** Rails: xero_enabled? (premium integration "xero"). */
    public function xeroEnabled(): bool
    {
        return \App\Support\License::premium()
            && in_array('xero', (array) ($this->premium_integrations ?? []), true);
    }

    /** Rails: netsuite_enabled? (premium integration "netsuite"). */
    public function netsuiteEnabled(): bool
    {
        return \App\Support\License::premium()
            && in_array('netsuite', (array) ($this->premium_integrations ?? []), true);
    }

    /** Rails: has_many :alerts, class_name: "UsageMonitoring::Alert". */
    public function alerts(): HasMany
    {
        return $this->hasMany(UsageMonitoring\Alert::class);
    }

    /** Rails: has_many :subscription_activities, class_name: "UsageMonitoring::SubscriptionActivity". */
    public function subscriptionActivities(): HasMany
    {
        return $this->hasMany(UsageMonitoring\SubscriptionActivity::class);
    }

    // -- Lifecycle (port of before_create :set_hmac_key / after_create
    // :generate_document_number_prefix) ----------------------------------

    protected static function booted(): void
    {
        static::creating(function (self $organization): void {
            if ($organization->hmac_key === null) {
                do {
                    $organization->hmac_key = (string) Str::uuid();
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

    // -- Attribute behavior (ports of Rails readers/writers/normalizers) ----

    protected function email(): Attribute
    {
        return Attribute::set(
            fn (?string $value) => EmailSanitizer::sanitize($value),
        );
    }

    /** Rails: `def document_number_prefix=(value); super(value&.upcase); end` */
    protected function documentNumberPrefix(): Attribute
    {
        return Attribute::set(
            fn (?string $value) => $value === null ? null : mb_strtoupper($value),
        );
    }

    /**
     * Rails: the organization delegates `default_currency` to its default
     * billing entity when one exists (migration of this data to billing
     * entities) — falls back to the column.
     */
    protected function defaultCurrency(): Attribute
    {
        return Attribute::get(
            function (?string $value) {
                $billingEntity = $this->defaultBillingEntity;

                return $billingEntity?->default_currency ?? $value;
            },
        );
    }

    /** Same delegation as default_currency. */
    protected function timezone(): Attribute
    {
        return Attribute::get(
            function (?string $value) {
                $billingEntity = $this->defaultBillingEntity;

                return $billingEntity?->timezone ?? $value;
            },
        );
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

        if (mb_strlen($slug) < 3) {
            $errors[] = 'value_is_too_short';
        }

        if (mb_strlen($slug) > 40) {
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

    protected function generateSlug(): string
    {
        $candidate = Str::slug((string) $this->name, '-', 'en');

        $candidate = mb_substr($candidate, 0, 40);
        $candidate = preg_replace('/\A-+|-+\z/', '', $candidate) ?? '';

        $generateRandom = function () {
            do {
                $candidate = 'org-'.mb_strtolower(Str::random(5));
            } while (static::where('slug', $candidate)->exists());

            return $candidate;
        };

        if (mb_strlen($candidate) < 3 || ctype_digit($candidate) || in_array($candidate, self::RESERVED_SLUGS, true)) {
            return $generateRandom();
        }

        $base = mb_rtrim(mb_substr($candidate, 0, 36), '-');
        while (static::where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.mb_strtolower(Str::random(3));
        }

        return $candidate;
    }

    protected function casts(): array
    {
        return [
            'vat_rate' => 'float',
            'invoice_grace_period' => 'integer',
            'net_payment_term' => 'integer',
            'email_settings' => PostgresArray::class,
            'premium_integrations' => PostgresArray::class,
            'feature_flags' => PostgresArray::class,
            'authentication_methods' => PostgresArray::class,
            'document_numbering' => DocumentNumbering::class,
            'eu_tax_management' => 'boolean',
            'custom_aggregation' => 'boolean',
            'finalize_zero_amount_invoice' => 'boolean',
            'audit_logs_period' => 'integer',
            'max_wallets' => 'integer',
        ];
    }

    private function premiumIntegrationEnabled(string $integration): bool
    {
        return \App\Support\License::premium()
            && in_array($integration, (array) ($this->premium_integrations ?? []), true);
    }
}
