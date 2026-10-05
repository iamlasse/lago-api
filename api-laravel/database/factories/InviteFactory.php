<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invite;
use App\Enums\InviteStatus;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :invite factory (spec/factories/invites.rb) — pending, one
 * admin role code, random token.
 *
 * @extends Factory<Invite>
 */
class InviteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'status' => InviteStatus::Pending,
            'email' => $this->faker->email(),
            'token' => bin2hex(random_bytes(20)),
            'roles' => ['admin'],
        ];
    }

    /** Rails trait equivalent: `status { :accepted }`. */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InviteStatus::Accepted,
            'accepted_at' => now(),
        ]);
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->for($organization);
    }
}
