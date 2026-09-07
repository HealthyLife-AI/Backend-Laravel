<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('9#########'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A nutritionist-role user (PRD §2.1).
     */
    public function nutritionist(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole('nutritionist'));
    }

    /**
     * A client-role user belonging to the given (or a freshly created)
     * nutritionist. BR-1: a client belongs to exactly one nutritionist.
     */
    public function client(?User $nutritionist = null): static
    {
        return $this->state(fn () => [
            'nutritionist_id' => $nutritionist?->id,
        ])->afterCreating(function (User $user) use ($nutritionist) {
            if ($nutritionist === null) {
                $user->forceFill([
                    'nutritionist_id' => User::factory()->nutritionist()->create()->id,
                ])->save();
            }

            $user->assignRole('client');
        });
    }

    /**
     * An admin-role user (curates the food database — PRD §2.1).
     */
    public function admin(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole('admin'));
    }
}
