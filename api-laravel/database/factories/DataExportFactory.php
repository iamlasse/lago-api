<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DataExport;
use App\Models\Membership;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for `data_exports` (Rails' :data_export factory).
 *
 * @extends Factory<DataExport>
 */
class DataExportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'membership_id' => MembershipFactory::new(),
            'format' => 0, // csv
            'resource_type' => 'invoices',
            'resource_query' => '{}',
            'status' => 0, // pending
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (): array => ['organization_id' => $organization->id]);
    }

    public function forMembership(Membership $membership): static
    {
        return $this->state(fn (): array => [
            'membership_id' => $membership->id,
            'organization_id' => $membership->organization_id,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (): array => ['status' => 1]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => 2,
            'completed_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => ['status' => 3]);
    }

    /** Rails factories use `resource_type "invoices"` / `"credit_notes"` etc. */
    public function creditNotes(): static
    {
        return $this->state(fn (): array => ['resource_type' => 'credit_notes']);
    }

    public function invoiceFees(): static
    {
        return $this->state(fn (): array => ['resource_type' => 'invoice_fees']);
    }

    public function creditNoteItems(): static
    {
        return $this->state(fn (): array => ['resource_type' => 'credit_note_items']);
    }
}
