<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Quote;
use App\Models\BillingEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of the :quote_version factory (spec/factories/quote_versions.rb) —
 * the ORDERS slice needs the billing_items snapshot, the currency and the
 * billing entity the execution bills on.
 *
 * @extends Factory<\App\Models\QuoteVersion>
 */
class QuoteVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'quote_id' => QuoteFactory::new(),
            'status' => 'draft',
        ];
    }

    /** Rails trait :approved. */
    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    /**
     * Rails trait :with_one_off_billing_items — a one-off deal billing a
     * single add-on snapshot (the billing_items keys are camelCase, the
     * frozen shape the execution replays).
     */
    public function withOneOffBillingItems(): static
    {
        return $this->state(fn (): array => [
            'currency' => 'EUR',
            'billing_items' => [
                'addOns' => [
                    [
                        'id' => (string) \Illuminate\Support\Str::uuid(),
                        'payload' => [
                            'code' => 'test_add_on',
                            'units' => 1,
                        ],
                    ],
                ],
            ],
        ]);
    }

    /** Rails trait :voided. */
    public function voided(string $reason = 'manual'): static
    {
        return $this->state(fn (): array => [
            'status' => 'voided',
            'void_reason' => $reason,
            'voided_at' => now(),
        ]);
    }

    /**
     * A subscription_creation snapshot over the given catalog plan: the
     * payload keys are camelCase, the frozen shape the validators replay.
     *
     * @param  array<string, mixed>  $payload
     */
    public function withPlanBillingItems(\App\Models\Plan $plan, array $payload = []): static
    {
        return $this->state(fn (): array => [
            'currency' => $plan->amount_currency ?? 'EUR',
            'billing_items' => [
                'plans' => [
                    [
                        'id' => $plan->id,
                        'type' => 'plan',
                        'payload' => array_merge([
                            'code' => $plan->code,
                        ], $payload),
                    ],
                ],
            ],
        ]);
    }

    public function forQuote(Quote $quote): static
    {
        return $this->state(fn (): array => [
            'quote_id' => $quote->id,
            'organization_id' => $quote->organization_id,
        ]);
    }

    public function withBillingEntity(BillingEntity $billingEntity): static
    {
        return $this->state(fn (): array => [
            'billing_entity_id' => $billingEntity->id,
        ]);
    }
}
