<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :user factory — password is ILoveLago (bcrypt digest via
 * the model's has_secure_password port).
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'email' => $this->faker->unique()->email(),
            'password' => 'ILoveLago',
        ];
    }
}
