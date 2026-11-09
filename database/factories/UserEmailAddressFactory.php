<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserEmailAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserEmailAddress>
 */
class UserEmailAddressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'confirmed_at' => null,
            'confirmation_sent_at' => now(),
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => ['confirmed_at' => now()]);
    }

    public function unconfirmed(): static
    {
        return $this->state(fn (): array => ['confirmed_at' => null]);
    }
}
