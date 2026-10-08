<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\ErrorDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :error_detail factory (spec/factories/error_details.rb —
 * organization + a sampled owner association; the port pins the owner to
 * Invoice, with states for the tax error codes).
 *
 * @extends Factory<ErrorDetail>
 */
class ErrorDetailFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'owner_type' => 'Invoice',
            'owner_id' => InvoiceFactory::new(),
            'error_code' => ErrorDetail::ERROR_CODES['not_provided'],
            'details' => [],
        ];
    }

    /** Rails: error_code :tax_error (the provider-taxes failure record). */
    public function taxError(): static
    {
        return $this->state(fn () => [
            'error_code' => ErrorDetail::ERROR_CODES['tax_error'],
        ]);
    }

    /** Rails: error_code :tax_voiding_error. */
    public function taxVoidingError(): static
    {
        return $this->state(fn () => [
            'error_code' => ErrorDetail::ERROR_CODES['tax_voiding_error'],
        ]);
    }

    /** Rails: error_code :invoice_generation_error. */
    public function invoiceGenerationError(): static
    {
        return $this->state(fn () => [
            'error_code' => ErrorDetail::ERROR_CODES['invoice_generation_error'],
        ]);
    }

    public function forOrganization(\App\Models\Organization $organization): static
    {
        return $this->for($organization, 'organization');
    }

    public function forOwner(\Illuminate\Database\Eloquent\Model $owner): static
    {
        return $this->for($owner, 'owner');
    }
}
