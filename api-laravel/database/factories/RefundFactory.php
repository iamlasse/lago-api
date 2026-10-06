<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Payment;
use App\Models\CreditNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :refund factory — a pending credit-note refund hanging off
 * a succeeded invoice payment.
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
                return Payment::query()->find($attributes['payment_id'] ?? null)?->id
                    ?? PaymentFactory::new()->create([
                        'organization_id' => $attributes['organization_id'],
                    ])->id;
            },
            'credit_note_id' => function (array $attributes): string {
                return CreditNote::query()->find($attributes['credit_note_id'] ?? null)?->id
                    ?? CreditNoteFactory::new()->create([
                        'organization_id' => $attributes['organization_id'],
                    ])->id;
            },
            'payment_provider_id' => null,
            'payment_provider_customer_id' => function (array $attributes): string {
                return Payment::query()->find($attributes['payment_id'])->payment_provider_customer_id
                    ?? PaymentProviderCustomerFactory::new()->create([
                        'organization_id' => $attributes['organization_id'],
                    ])->id;
            },
            'amount_cents' => 100,
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
}
