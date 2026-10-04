<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\PaymentIntent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :payment_intent factory — a hosted-checkout link record.
 *
 * @extends Factory<PaymentIntent>
 */
class PaymentIntentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_id' => InvoiceFactory::new(),
            'organization_id' => function (array $attributes): string {
                return Invoice::query()->find($attributes['invoice_id'])->organization_id;
            },
            'status' => 0,
            'expires_at' => now()->addHours(24),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['status' => 1, 'expires_at' => now()->subMinute()]);
    }

    public function forInvoice(Invoice $invoice): static
    {
        return $this->state(fn () => [
            'invoice_id' => $invoice->id,
            'organization_id' => $invoice->organization_id,
        ]);
    }
}
