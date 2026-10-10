<?php

namespace Database\Factories;

use App\Enums\GameStatus;
use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Game> */
class GameFactory extends Factory
{
    protected $model = Game::class;

    public function definition(): array
    {
        return [
            'code' => Game::freshCode(),
            'host_token' => Str::random(64),
            'title' => 'The Hollow Beneath Greyfell',
            'premise' => 'A mining town has gone silent and the party has been paid half up front.',
            'tone' => 'sunlit',
            'status' => GameStatus::Lobby,
            'scene_number' => 0,
            'settings' => config('questcast.defaults'),
        ];
    }

    public function awaitingActions(): static
    {
        return $this->state(['status' => GameStatus::AwaitingActions, 'scene_number' => 1]);
    }
}
