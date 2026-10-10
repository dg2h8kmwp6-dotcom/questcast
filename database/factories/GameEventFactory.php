<?php

namespace Database\Factories;

use App\Enums\EventType;
use App\Models\Game;
use App\Models\GameEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GameEvent> */
class GameEventFactory extends Factory
{
    protected $model = GameEvent::class;

    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'scene_id' => null,
            'type' => EventType::System,
            'body' => 'Something happened.',
            'meta' => [],
        ];
    }
}
