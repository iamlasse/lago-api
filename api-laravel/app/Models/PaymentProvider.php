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
        if ($this->isStripe()) {
            return match (true) {
                in_array($paymentStatus, self::STRIPE_PROCESSING_STATUSES, true) => 'processing',
                in_array($paymentStatus, self::STRIPE_SUCCESS_STATUSES, true) => 'succeeded',
                in_array($paymentStatus, self::STRIPE_FAILED_STATUSES, true) => 'failed',
                default => $paymentStatus,
            };
        }

        return $paymentStatus;
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
