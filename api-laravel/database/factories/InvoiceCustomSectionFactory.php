<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InvoiceCustomSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :invoice_custom_section factory.
 *
 * @extends Factory<InvoiceCustomSection>
 */
class InvoiceCustomSectionFactory extends Factory
{
    protected $model = InvoiceCustomSection::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'name' => 'Custom section name',
            'code' => 'custom_section',
            'description' => 'Custom section description',
            'details' => 'Custom section details',
            'display_name' => 'Custom section display name',
            'section_type' => \App\Enums\InvoiceCustomSectionType::Manual,
        ];
    }

    public function systemGenerated(): static
    {
        return $this->state(fn (): array => [
            'section_type' => \App\Enums\InvoiceCustomSectionType::SystemGenerated,
        ]);
    }
}
