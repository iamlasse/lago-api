<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Organization;
use App\Models\PaymentReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :payment_receipt factory — the number column is normally
 * assigned by the frozen schema's set_payment_receipt_number() trigger
 * (the factory provides one explicitly so tests can insert without a
 * payable chain; use forPayment() to exercise the trigger).
 *
 * @extends Factory<PaymentReceipt>
 */
class PaymentReceiptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => 'RCPT-'.$this->faker->randomNumber(6),
            'payment_id' => PaymentFactory::new(),
            'organization_id' => function (array $attributes): string {
                return Organization::query()->find($attributes['organization_id'] ?? null)->id
                    ?? Payment::query()->find($attributes['payment_id'])->organization_id;
            },
            'billing_entity_id' => function (array $attributes): string {
                $payment = Payment::query()->find($attributes['payment_id']);
                $payable = $payment?->payable;

                return $payable?->billing_entity_id
                    ?? \App\Models\BillingEntity::factory()->create([
                        'organization_id' => $attributes['organization_id'],
                    ])->id;
            },
        ];
    }

    /**
     * Insert with number = NULL so the frozen set_payment_receipt_number()
     * trigger assigns it (the Rails production path).
     */
    public function viaTrigger(): static
    {
        return $this->state(fn () => ['number' => null]);
    }

    public function forPayment(Payment $payment): static
    {
        return $this->state(fn () => [
            'payment_id' => $payment->id,
            'organization_id' => $payment->organization_id,
        ]);
    }
}
