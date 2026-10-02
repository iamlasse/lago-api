<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Membership;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :membership factory.
 *
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
            'organization_id' => OrganizationFactory::new(),
            'status' => 0,
        ];
    }

    /** Rails trait :revoked. */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 1,
            'revoked_at' => now(),
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->for($user);
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->for($organization);
    }
}
