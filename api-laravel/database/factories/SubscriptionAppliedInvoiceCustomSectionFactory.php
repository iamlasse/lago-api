<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\SubscriptionAppliedInvoiceCustomSection;

/**
 * Port of Rails' :Subscription applied_invoice_custom_section factory
 * (Subscription::AppliedInvoiceCustomSection — the Subscription join table).
 *
 * @extends Factory<SubscriptionAppliedInvoiceCustomSection>
 */
class SubscriptionAppliedInvoiceCustomSectionFactory extends Factory
{
    protected $model = SubscriptionAppliedInvoiceCustomSection::class;

    public function definition(): array
    {
        return [
            'subscription_id' => SubscriptionFactory::new(),
            'invoice_custom_section_id' => InvoiceCustomSectionFactory::new(),
            'organization_id' => function (array $attributes): string {
                $parent = $attributes['subscription_id'] instanceof Subscription
                    ? $attributes['subscription_id']
                    : Subscription::query()->findOrFail((string) $attributes['subscription_id']);

                return (string) $parent->organization_id;
            },
        ];
    }
}
