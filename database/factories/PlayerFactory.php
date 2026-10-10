<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Player> */
class PlayerFactory extends Factory
{
    protected $model = Player::class;

    public function definition(): array
    {
        $archetype = $this->faker->randomElement(array_keys(config('questcast.archetypes')));

        return [
            'game_id' => Game::factory(),
            'token' => Str::random(64),
            'name' => $this->faker->unique()->firstName(),
            'character_name' => null,
            'archetype' => $archetype,
            'stats' => config("questcast.archetypes.{$archetype}"),
            'max_hp' => 12,
            'hp' => 12,
            'inventory' => [],
            'conditions' => [],
            'last_seen_at' => now(),
        ];
    }

    public function down(): static
    {
        return $this->state(['hp' => 0]);
    }
}
