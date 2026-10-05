<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `payment_provider_customers` (Rails'
 * PaymentProviderCustomers::BaseCustomer — single-table inheritance on
 * `type`; subclasses are not ported yet, so the base model carries the
 * columns).
 */
#[Fillable([
    'customer_id',
    'payment_provider_id',
    'type',
    'provider_customer_id',
    'settings',
    'deleted_at',
    'organization_id',
    'code',
    'is_default',
])]
#[Table(name: 'payment_provider_customers')]
class PaymentProviderCustomer extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // -- Settings accessors (Rails: SettingsStorable on BaseCustomer) --------

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

    /** Rails: StripeCustomer#provider_payment_methods. */
    public function providerPaymentMethods(): ?array
    {
        $methods = $this->getFromSettings('provider_payment_methods');

        return is_array($methods) ? $methods : null;
    }

    /** Rails: StripeCustomer#provider_payment_methods_with_setup. */
    public function providerPaymentMethodsWithSetup(): array
    {
        $methods = $this->providerPaymentMethods() ?? [];

        return array_values(array_intersect(
            $methods,
            ['card', 'sepa_debit', 'us_bank_account', 'bacs_debit', 'link', 'boleto'],
        ));
    }

    /** Rails: StripeCustomer#provider_payment_methods_require_setup?. */
    public function providerPaymentMethodsRequireSetup(): bool
    {
        return $this->providerPaymentMethodsWithSetup() !== [];
    }

    /** Rails: legacy_provider_method_id (settings payment_method_id / provider_mandate_id). */
    public function legacyProviderMethodId(): ?string
    {
        return $this->getFromSettings('payment_method_id')
            ?? $this->getFromSettings('provider_mandate_id');
    }

    /** Rails: belongs_to :payment_provider (BaseCustomer). */
    public function paymentProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class);
    }

    /** Rails: MoneyhashCustomer#mh_billing_data — derived from the customer. */
    public function mhBillingData(): array
    {
        $customer = $this->customer;

        $billingData = array_filter([
            'name' => $customer?->name,
            'first_name' => $customer?->firstname,
            'last_name' => $customer?->lastname,
            'email' => $customer?->email,
            'phone_number' => $customer?->phone,
            'address' => $customer?->address_line1,
            'address1' => $customer?->address_line2,
            'city' => $customer?->city,
            'state' => $customer?->state,
            'country' => $customer?->country,
            'postal_code' => $customer?->zipcode,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        /** @var array<string, mixed> $billingData */
        return $billingData;
    }

    /** Rails: MoneyhashCustomer#mh_custom_fields — derived from the customer. */
    public function mhCustomFields(): array
    {
        $customer = $this->customer;

        return [
            // connection
            'lago_mh_connection_id' => $this->payment_provider_id,
            'lago_mh_connection_code' => $this->paymentProvider?->code,
            // customer
            'lago_customer_id' => $customer?->id,
            'lago_customer_external_id' => (string) ($customer?->external_id ?? ''),
            'lago_customer_name' => (string) ($customer?->name ?? ''),
            'lago_customer_currency' => (string) ($customer?->currency ?? ''),
            'lago_customer_legal_name' => (string) ($customer?->legal_name ?? ''),
            'lago_customer_legal_number' => (string) ($customer?->legal_number ?? ''),
            'lago_customer_tax_identification_number' => (string) ($customer?->tax_identification_number ?? ''),
            'lago_customer_provider_customer_id' => (string) ($this->provider_customer_id ?? ''),
            // organization
            'lago_organization_id' => $customer?->organization_id,
        ];
    }

    /** Rails: MoneyhashCustomer settings_accessors :payment_method_id. */
    public function moneyhashPaymentMethodId(): ?string
    {
        $value = $this->getFromSettings('payment_method_id');

        return $value === null ? null : (string) $value;
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_default' => 'boolean',
        ];
    }
}
