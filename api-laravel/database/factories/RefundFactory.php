<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Payment;
use App\Models\CreditNote;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :refund factory — a pending credit-note refund hanging off
 * a succeeded invoice payment (Rails associates a stripe provider +
 * provider customer by default; here the payment's own provider pair is
 * reused).
 *
 * @extends Factory<\App\Models\Refund>
 */
class RefundFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'payment_id' => function (array $attributes): string {
                $paymentId = $attributes['payment_id'] ?? null;

                return Payment::query()->find(is_string($paymentId) ? $paymentId : null)?->id
                    ?? self::fallbackPayment($attributes)->id;
            },
            'credit_note_id' => function (array $attributes): string {
                $creditNoteId = $attributes['credit_note_id'] ?? null;

                return CreditNote::query()->find(is_string($creditNoteId) ? $creditNoteId : null)?->id
                    ?? CreditNoteFactory::new()->create([
                        'organization_id' => $attributes['organization_id'],
                    ])->id;
            },
            'payment_provider_id' => function (array $attributes): ?string {
                return Payment::query()->find($attributes['payment_id'])?->payment_provider_id;
            },
            'payment_provider_customer_id' => function (array $attributes): string {
                $payment = Payment::query()->find($attributes['payment_id']);

                return $payment->payment_provider_customer_id
                    ?? PaymentProviderCustomerFactory::new()->forCustomer($payment->customer)->create()->id;
            },
            'amount_cents' => 200,
            'amount_currency' => 'EUR',
            'status' => 'pending',
            'provider_refund_id' => 're_'.fake()->uuid(),
            'refundable_type' => 'CreditNote',
            'refundable_id' => function (array $attributes): string {
                return $attributes['credit_note_id'];
            },
            'reason' => 'credit_note',
        ];
    }

    /**
     * A plain succeeded payment for a standalone refund (the factory
     * fallback) — PaymentFactory's closures need an explicit customer id.
     */
    private static function fallbackPayment(array $attributes): Payment
    {
        $organizationId = $attributes['organization_id'];
        $organization = $organizationId instanceof OrganizationFactory
            ? $organizationId->create()
            : Organization::query()->find($organizationId);

        $customer = CustomerFactory::new()->create(['organization_id' => $organization->id]);

        return PaymentFactory::new()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
        ]);
    }
}
