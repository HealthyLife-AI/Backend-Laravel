<?php

namespace Database\Factories;

use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscriber>
 */
class SubscriberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Eagerly create a matched nutritionist/client pair so the two FKs
        // are always consistent with each other by default; a test that
        // needs a specific nutritionist overrides both via ->create([...]).
        $nutritionist = User::factory()->nutritionist()->create();

        $client = User::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $client->assignRole('client');

        return [
            'user_id' => $client->id,
            'nutritionist_id' => $nutritionist->id,
            'code' => 'PT-'.fake()->unique()->numberBetween(100, 999),
            'goal' => fake()->randomElement(['weight_loss', 'weight_gain', 'weight_maintenance', 'health_monitoring']),
            'status' => 'pending',
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => 'active']);
    }
}
