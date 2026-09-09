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

    /**
     * Ties `user_id` to a real client of `$nutritionist`, not just
     * `nutritionist_id` to `$nutritionist->id`. Overriding `nutritionist_id`
     * alone via a plain `->create(['nutritionist_id' => ...])` leaves
     * `user_id` pointing at `definition()`'s own internally-created,
     * unrelated client — harmless for a test that only ever authenticates
     * as the nutritionist, but silently wrong for one that needs to
     * authenticate as `$subscriber->user` (a client-facing endpoint,
     * Sprint 3+): `NutritionistScope` then filters that client's own
     * subscriber row out from under them, since their real
     * `nutritionist_id` doesn't match the override.
     */
    public function forNutritionist(User $nutritionist): static
    {
        return $this->state(function () use ($nutritionist) {
            $client = User::factory()->client($nutritionist)->create();

            return ['nutritionist_id' => $nutritionist->id, 'user_id' => $client->id];
        });
    }
}
