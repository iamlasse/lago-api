<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `payment_providers` (Rails'
 * PaymentProviders::BaseProvider — single-table inheritance on `type`;
 * subclasses are not ported, so the base model carries the columns and the
 * Stripe-specific constants live in App\Services\PaymentProviders\Stripe).
 *
 * Rails stores the STI class in `type` (e.g. "PaymentProviders::StripeProvider");
 * `paymentType()` derives the provider slug ("stripe") from it, matching
 * StripeProvider#payment_type. `secrets` is Rails `encrypts :secrets` — a JSON
 * object of secrets (stored as plaintext JSON in this port; TODO(port)
 * encryption at rest).
 */
#[Fillable([
    'organization_id',
    'type',
    'secrets',
    'settings',
    'code',
    'name',
    'deleted_at',
])]
#[Table(name: 'payment_providers')]
class PaymentProvider extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    /** Rails: PaymentProviders::StripeProvider::PROCESSING_STATUSES. */
    public const STRIPE_PROCESSING_STATUSES = ['processing', 'requires_capture', 'requires_action', 'requires_confirmation'];

    /** Rails: PaymentProviders::StripeProvider::SUCCESS_STATUSES. */
    public const STRIPE_SUCCESS_STATUSES = ['succeeded'];

    /** Rails: PaymentProviders::StripeProvider::FAILED_STATUSES. */
    public const STRIPE_FAILED_STATUSES = ['canceled', 'requires_payment_method'];

    /** Rails: PaymentProviders::StripeProvider::AMOUNT_TOO_SMALL_ERROR_CODE. */
    public const STRIPE_AMOUNT_TOO_SMALL_ERROR_CODE = 'amount_too_small';

    /** Rails: PaymentProviders::StripeProvider::NEED_3DS_ERROR_CODE. */
    public const STRIPE_NEED_3DS_ERROR_CODE = 'authentication_required';

    /** Rails: PaymentProviders::StripeProvider::SUCCESS_REDIRECT_URL. */
    public const STRIPE_SUCCESS_REDIRECT_URL = 'https://stripe.com/';

    /** Rails: PaymentProviders::AdyenProvider::SUCCESS_REDIRECT_URL. */
    public const ADYEN_SUCCESS_REDIRECT_URL = 'https://www.adyen.com/';

    /** Rails: PaymentProviders::AdyenProvider::PROCESSING_STATUSES. */
    public const ADYEN_PROCESSING_STATUSES = ['AuthorisedPending', 'Received'];

    /** Rails: PaymentProviders::AdyenProvider::SUCCESS_STATUSES. */
    public const ADYEN_SUCCESS_STATUSES = ['Authorised', 'SentForSettle', 'SettleScheduled', 'Settled', 'Refunded'];

    /** Rails: PaymentProviders::AdyenProvider::FAILED_STATUSES. */
    public const ADYEN_FAILED_STATUSES = ['Cancelled', 'CaptureFailed', 'Error', 'Expired', 'Refused'];

    /** Rails: PaymentProviders::AdyenProvider::WEBHOOKS_EVENTS. */
    public const ADYEN_WEBHOOKS_EVENTS = ['AUTHORISATION', 'CANCELLATION', 'REFUND', 'REFUND_FAILED', 'CHARGEBACK'];

    /** Rails: PaymentProviders::AdyenProvider::IGNORED_WEBHOOK_EVENTS. */
    public const ADYEN_IGNORED_WEBHOOK_EVENTS = ['OFFER_CLOSED', 'REPORT_AVAILABLE', 'RECURRING_CONTRACT'];

    /** Rails: PaymentProviders::GocardlessProvider::SUCCESS_REDIRECT_URL. */
    public const GOCARDLESS_SUCCESS_REDIRECT_URL = 'https://gocardless.com/';

    /** Rails: PaymentProviders::GocardlessProvider::PROCESSING_STATUSES. */
    public const GOCARDLESS_PROCESSING_STATUSES = ['pending_customer_approval', 'pending_submission', 'submitted', 'confirmed'];

    /** Rails: PaymentProviders::GocardlessProvider::SUCCESS_STATUSES. */
    public const GOCARDLESS_SUCCESS_STATUSES = ['paid_out'];

    /** Rails: PaymentProviders::GocardlessProvider::FAILED_STATUSES. */
    public const GOCARDLESS_FAILED_STATUSES = ['cancelled', 'customer_approval_denied', 'failed', 'charged_back'];

    /** Rails: PaymentProviders::CashfreeProvider::SUCCESS_REDIRECT_URL. */
    public const CASHFREE_SUCCESS_REDIRECT_URL = 'https://cashfree.com/';

    /** Rails: PaymentProviders::CashfreeProvider::API_VERSION. */
    public const CASHFREE_API_VERSION = '2023-08-01';

    /** Rails: PaymentProviders::CashfreeProvider::BASE_URL (env-dependent). */
    public const CASHFREE_PRODUCTION_BASE_URL = 'https://api.cashfree.com/pg/links';
    public const CASHFREE_SANDBOX_BASE_URL = 'https://sandbox.cashfree.com/pg/links';

    /** Rails: PaymentProviders::CashfreeProvider::PROCESSING_STATUSES. */
    public const CASHFREE_PROCESSING_STATUSES = ['PARTIALLY_PAID'];

    /** Rails: PaymentProviders::CashfreeProvider::SUCCESS_STATUSES. */
    public const CASHFREE_SUCCESS_STATUSES = ['PAID'];

    /** Rails: PaymentProviders::CashfreeProvider::FAILED_STATUSES. */
    public const CASHFREE_FAILED_STATUSES = ['EXPIRED', 'CANCELLED'];

    /** Rails: PaymentProviders::FlutterwaveProvider::SUCCESS_REDIRECT_URL. */
    public const FLUTTERWAVE_SUCCESS_REDIRECT_URL = 'https://www.flutterwave.com/ng';

    /** Rails: PaymentProviders::FlutterwaveProvider::API_URL. */
    public const FLUTTERWAVE_API_URL = 'https://api.flutterwave.com/v3';

    /** Rails: PaymentProviders::FlutterwaveProvider::PROCESSING_STATUSES. */
    public const FLUTTERWAVE_PROCESSING_STATUSES = ['pending'];

    /** Rails: PaymentProviders::FlutterwaveProvider::SUCCESS_STATUSES. */
    public const FLUTTERWAVE_SUCCESS_STATUSES = ['successful'];

    /** Rails: PaymentProviders::FlutterwaveProvider::FAILED_STATUSES. */
    public const FLUTTERWAVE_FAILED_STATUSES = ['failed', 'cancelled'];

    /** Rails: PaymentProviders::MoneyhashProvider::SUCCESS_REDIRECT_URL. */
    public const MONEYHASH_SUCCESS_REDIRECT_URL = 'https://moneyhash.io/';

    /** Rails: MoneyhashProvider::PROCESSING_STATUSES (Lago status -> MH). */
    public const MONEYHASH_PROCESSING_STATUSES = ['PENDING', 'PENDING_AUTHENTICATION', 'UNPROCESSED'];

    /** Rails: MoneyhashProvider::SUCCESS_STATUSES. */
    public const MONEYHASH_SUCCESS_STATUSES = ['SUCCESSFUL', 'PROCESSED'];

    /** Rails: MoneyhashProvider::FAILED_STATUSES. */
    public const MONEYHASH_FAILED_STATUSES = ['FAILED'];

    /** Rails: MoneyhashProvider::PAYABLE_PAYMENT_STATUS_MAP (MH -> Lago). */
    public const MONEYHASH_PAYABLE_PAYMENT_STATUS_MAP = [
        'PENDING' => 'pending',
        'PENDING_AUTHENTICATION' => 'pending',
        'UNPROCESSED' => 'pending',
        'SUCCESSFUL' => 'succeeded',
        'PROCESSED' => 'succeeded',
        'FAILED' => 'failed',
    ];

    /**
     * Rails: PaymentProviders::StripeProvider::WEBHOOKS_EVENTS (the events the
     * Lago-managed Stripe webhook endpoint subscribes to).
     *
     * @return list<string>
     */
    public const STRIPE_WEBHOOKS_EVENTS = [
        'setup_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.succeeded',
        'payment_intent.canceled',
        'payment_method.detached',
        'charge.refund.updated',
        'customer.updated',
        'charge.dispute.closed',
        'customer_cash_balance_transaction.created',
    ];

    /** "PaymentProviders::StripeProvider" -> "stripe" (Rails' classify inverse). */
    public static function typeToSlug(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if (Str::endsWith($type, 'Provider')) {
            $type = mb_substr($type, 0, -mb_strlen('Provider'));
        }

        return mb_strtolower((string) Str::afterLast($type, '::')) ?: null;
    }

    /** "stripe" -> "PaymentProviders::StripeProvider" (Rails' FindService scope). */
    public static function slugToType(string $slug): string
    {
        return 'PaymentProviders::'.ucfirst($slug).'Provider';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function paymentProviderCustomers(): HasMany
    {
        return $this->hasMany(PaymentProviderCustomer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    /** The provider slug ("stripe") — Rails' StripeProvider#payment_type. */
    public function paymentType(): ?string
    {
        return self::typeToSlug($this->type);
    }

    public function isStripe(): bool
    {
        return $this->paymentType() === 'stripe';
    }

    /**
     * Rails: BaseProvider#determine_payment_status — maps a provider-side
     * status onto a payment.payable_payment_status. Stripe's status lists
     * are the only ones ported; unknown types pass the status through.
     */
    public function determinePaymentStatus(string $paymentStatus): string
    {
        $statuses = match ($this->paymentType()) {
            'stripe' => [
                self::STRIPE_PROCESSING_STATUSES,
                self::STRIPE_SUCCESS_STATUSES,
                self::STRIPE_FAILED_STATUSES,
            ],
            'adyen' => [
                self::ADYEN_PROCESSING_STATUSES,
                self::ADYEN_SUCCESS_STATUSES,
                self::ADYEN_FAILED_STATUSES,
            ],
            'gocardless' => [
                self::GOCARDLESS_PROCESSING_STATUSES,
                self::GOCARDLESS_SUCCESS_STATUSES,
                self::GOCARDLESS_FAILED_STATUSES,
            ],
            'cashfree' => [
                self::CASHFREE_PROCESSING_STATUSES,
                self::CASHFREE_SUCCESS_STATUSES,
                self::CASHFREE_FAILED_STATUSES,
            ],
            'flutterwave' => [
                self::FLUTTERWAVE_PROCESSING_STATUSES,
                self::FLUTTERWAVE_SUCCESS_STATUSES,
                self::FLUTTERWAVE_FAILED_STATUSES,
            ],
            'moneyhash' => [
                self::MONEYHASH_PROCESSING_STATUSES,
                self::MONEYHASH_SUCCESS_STATUSES,
                self::MONEYHASH_FAILED_STATUSES,
            ],
            default => null,
        };

        if ($statuses === null) {
            return $paymentStatus;
        }

        [$processing, $success, $failed] = $statuses;

        return match (true) {
            in_array($paymentStatus, $processing, true) => 'processing',
            in_array($paymentStatus, $success, true) => 'succeeded',
            in_array($paymentStatus, $failed, true) => 'failed',
            default => $paymentStatus,
        };
    }

    /**
     * Rails: MoneyhashProvider#payable_payment_status — the MoneyHash
     * payment status mapped onto a Lago payable_payment_status (null for
     * an unknown status).
     */
    public function payablePaymentStatus(string $mhStatus): ?string
    {
        return self::MONEYHASH_PAYABLE_PAYMENT_STATUS_MAP[$mhStatus] ?? null;
    }

    /** Rails: AdyenProvider#environment / MoneyhashProvider#environment. */
    public function adyenStyleEnvironment(): string
    {
        return app()->isProduction() && (string) $this->livePrefix() !== '' ? 'live' : 'test';
    }

    /** Rails: GocardlessProvider#environment. */
    public function gocardlessEnvironment(): string
    {
        return app()->isProduction() ? 'live' : 'sandbox';
    }

    /** Rails: PaymentProviders::CashfreeProvider::BASE_URL. */
    public static function cashfreeBaseUrl(): string
    {
        return app()->isProduction() ? self::CASHFREE_PRODUCTION_BASE_URL : self::CASHFREE_SANDBOX_BASE_URL;
    }

    /** Rails: PaymentProviders::MoneyhashProvider.api_base_url. */
    public static function moneyhashApiBaseUrl(): string
    {
        return app()->isProduction() ? 'https://web.moneyhash.io' : 'https://staging-web.moneyhash.io';
    }

    /** Rails: MoneyhashProvider#webhook_end_point. */
    public function webhookEndPoint(): string
    {
        $base = rtrim((string) env('LAGO_API_URL', ''), '/');

        return $base.'/webhooks/moneyhash/'.$this->organization_id.'?code='.urlencode($this->code);
    }

    // -- Settings/secrets accessors (Rails: settings_accessors / secrets_accessors)

    public function webhookSecret(): mixed
    {
        return $this->getFromSettings('webhook_secret');
    }

    public function setWebhookSecret(mixed $value): void
    {
        $this->pushToSettings('webhook_secret', $value);
    }

    public function webhookId(): mixed
    {
        return $this->getFromSettings('webhook_id');
    }

    public function setWebhookId(mixed $value): void
    {
        $this->pushToSettings('webhook_id', $value);
    }

    public function successRedirectUrl(): mixed
    {
        return $this->getFromSettings('success_redirect_url');
    }

    public function setSuccessRedirectUrl(mixed $value): void
    {
        $this->pushToSettings('success_redirect_url', $value);
    }

    public function supports3ds(): mixed
    {
        return $this->getFromSettings('supports_3ds');
    }

    public function setSupports3ds(mixed $value): void
    {
        $this->pushToSettings('supports_3ds', $value);
    }

    public function requireTermsOfServiceConsent(): bool
    {
        return (bool) ($this->getFromSettings('require_terms_of_service_consent') ?? false);
    }

    public function setRequireTermsOfServiceConsent(mixed $value): void
    {
        $this->pushToSettings('require_terms_of_service_consent', (bool) $value);
    }

    /** Rails: SecretsStorable#push_to_secrets. */
    public function setSecretKey(mixed $value): void
    {
        $secrets = $this->secrets ?? [];
        $secrets['secret_key'] = $value;
        $this->secrets = $secrets;
    }

    /** Rails: SecretsStorable#get_from_secrets. */
    public function secretKey(): mixed
    {
        return $this->secrets['secret_key'] ?? null;
    }

    // -- Non-Stripe settings/secrets accessors --------------------------------
    // Rails: settings_accessors / secrets_accessors per provider class.

    /** Rails: AdyenProvider secrets_accessors :api_key (secrets). */
    public function apiKey(): mixed
    {
        return $this->secrets['api_key'] ?? null;
    }

    /** Rails: AdyenProvider secrets_accessors :hmac_key (secrets). */
    public function hmacKey(): mixed
    {
        return $this->secrets['hmac_key'] ?? null;
    }

    /** Rails: AdyenProvider settings_accessors :live_prefix. */
    public function livePrefix(): mixed
    {
        return $this->settings['live_prefix'] ?? null;
    }

    /** Rails: AdyenProvider settings_accessors :merchant_account. */
    public function merchantAccount(): mixed
    {
        return $this->settings['merchant_account'] ?? null;
    }

    /** Rails: GocardlessProvider secrets_accessors :access_token (secrets). */
    public function accessToken(): mixed
    {
        return $this->secrets['access_token'] ?? null;
    }

    /** Rails: CashfreeProvider secrets_accessors :client_id (secrets). */
    public function clientId(): mixed
    {
        return $this->secrets['client_id'] ?? null;
    }

    /** Rails: CashfreeProvider secrets_accessors :client_secret (secrets). */
    public function clientSecret(): mixed
    {
        return $this->secrets['client_secret'] ?? null;
    }

    /** Rails: MoneyhashProvider secrets_accessors :signature_key (secrets). */
    public function signatureKey(): mixed
    {
        return $this->secrets['signature_key'] ?? null;
    }

    /** Rails: MoneyhashProvider settings_accessors :flow_id. */
    public function flowId(): mixed
    {
        return $this->settings['flow_id'] ?? null;
    }

    /**
     * Rails: FlutterwaveProvider overrides the base settings accessor — its
     * webhook_secret lives in secrets (secrets_accessors :webhook_secret).
     */
    public function flutterwaveWebhookSecret(): mixed
    {
        return $this->secrets['webhook_secret'] ?? null;
    }

    /** Rails: FlutterwaveProvider#generate_webhook_secret (before_create). */
    public function generateFlutterwaveWebhookSecret(): void
    {
        $secrets = $this->secrets ?? [];

        if (($secrets['webhook_secret'] ?? null) === null || $secrets['webhook_secret'] === '') {
            $secrets['webhook_secret'] = bin2hex(random_bytes(32));
        }

        $this->secrets = $secrets;
    }

    public function getFromSettings(string $key): mixed
    {
        return $this->settings[$key] ?? null;
    }

    public function pushToSettings(string $key, mixed $value): void
    {
        $settings = $this->settings ?? [];
        $settings[$key] = $value;
        $this->settings = $settings;
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            // Rails: `encrypts :secrets` (JSON object of secrets). Stored as
            // plaintext JSON here — TODO(port) encryption at rest once an
            // APP_KEY is guaranteed in every environment (the encrypted cast
            // raises MissingAppKeyException where APP_KEY is empty).
            'secrets' => 'array',
        ];
    }
}
