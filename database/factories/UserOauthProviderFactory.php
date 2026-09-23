<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserOauthProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserOauthProvider>
 */
class UserOauthProviderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider_name' => fake()->randomElement(['facebook', 'x', 'linkedin-openid', 'google']),
            'provider_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'token' => fake()->sha256(),
        ];
    }
}
