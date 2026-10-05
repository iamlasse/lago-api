<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Quote;
use App\Models\QuoteOwner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :quote_owner factory (spec/factories/quote_owners.rb).
 *
 * @extends Factory<QuoteOwner>
 */
class QuoteOwnerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'quote_id' => QuoteFactory::new(),
            'user_id' => UserFactory::new(),
        ];
    }

    public function forQuote(Quote $quote): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $quote->organization_id,
            'quote_id' => $quote->id,
        ]);
    }
}
