<?php

namespace Database\Factories;

use App\Enums\SceneStatus;
use App\Models\Game;
use App\Models\Scene;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Scene> */
class SceneFactory extends Factory
{
    protected $model = Scene::class;

    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'number' => 1,
            'status' => SceneStatus::Open,
            'narration' => 'The road narrows between two shoulders of black rock.',
            'options' => [
                ['key' => 'a', 'label' => 'Press forward carefully', 'skill' => 'wisdom', 'dc' => 12],
                ['key' => 'b', 'label' => 'Scout ahead alone', 'skill' => 'dexterity', 'dc' => 14],
                ['key' => 'c', 'label' => 'Hold position and listen', 'skill' => null, 'dc' => null],
            ],
        ];
    }
}
