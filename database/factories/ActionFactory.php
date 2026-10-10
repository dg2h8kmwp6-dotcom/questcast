<?php

namespace Database\Factories;

use App\Models\Action;
use App\Models\Player;
use App\Models\Scene;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Action> */
class ActionFactory extends Factory
{
    protected $model = Action::class;

    public function definition(): array
    {
        return [
            'scene_id' => Scene::factory(),
            'player_id' => Player::factory(),
            'option_key' => 'a',
            'skill' => 'wisdom',
            'dc' => 12,
            'roll' => 14,
            'modifier' => 1,
            'total' => 15,
            'success' => true,
            'critical' => false,
        ];
    }
}
