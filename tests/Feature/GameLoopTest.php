<?php

use App\Enums\GameStatus;
use App\Enums\SceneStatus;
use App\Events\GameUpdated;
use App\Models\Game;
use App\Models\Player;
use App\Models\Scene;
use App\Services\DungeonMaster\Contracts\DungeonMaster;
use App\Services\GameEngine;
use App\Support\Dice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    // The scripted DM keeps these tests offline; see config/questcast.php.
    config()->set('questcast.dm.driver', 'scripted');
});

function engine(?int $forcedRoll = null): GameEngine
{
    return new GameEngine(
        app(DungeonMaster::class),
        $forcedRoll === null ? new Dice : Dice::fixed($forcedRoll),
    );
}

it('gives every game a distinct, readable code', function () {
    $codes = collect(range(1, 40))->map(fn () => Game::freshCode());

    expect($codes->unique())->toHaveCount(40);
    expect($codes->every(fn ($code) => strlen($code) === 5))->toBeTrue();

    // Glyphs people confuse when reading off a screen are excluded.
    expect($codes->implode(''))->not->toContain('O', 'I', '0', '1');
});

it('seats a player with stats and hit points from their archetype', function () {
    $game = Game::factory()->create();

    $player = engine()->join($game, 'Ana', 'Runewright');

    expect($player->archetype)->toBe('Runewright')
        ->and($player->stats['intelligence'])->toBe(17)
        ->and($player->hp)->toBe($player->max_hp)
        ->and($player->token)->toHaveLength(64);

    // A Runewright's constitution of 11 adds nothing to the base pool.
    expect($player->max_hp)->toBe(config('questcast.defaults.starting_hp'));
});

it('refuses a second player with the same name at one table', function () {
    $game = Game::factory()->create();
    engine()->join($game, 'Ana', 'Scout');

    engine()->join($game, 'Ana', 'Songsmith');
})->throws(QueryException::class);

it('refuses to seat anyone once the adventure is running', function () {
    $game = Game::factory()->awaitingActions()->create();

    engine()->join($game, 'Latecomer', 'Brawler');
})->throws(RuntimeException::class, 'already begun');

it('refuses to begin with an empty table', function () {
    engine()->begin(Game::factory()->create());
})->throws(RuntimeException::class, 'Nobody has joined');

it('opens scene one when the host begins', function () {
    $game = Game::factory()->create();
    $engine = engine();
    $engine->join($game, 'Ana', 'Brawler');

    // The queue runs inline under test, so begin() carries through to a scene.
    $engine->begin($game->fresh());

    $game->refresh();

    expect($game->status)->toBe(GameStatus::AwaitingActions)
        ->and($game->scene_number)->toBe(1)
        ->and($game->currentScene->narration)->not->toBeEmpty()
        ->and($game->currentScene->options)->not->toBeEmpty();
});

it('rolls the dice when an action is submitted, not when it is resolved', function () {
    $game = Game::factory()->awaitingActions()->create();
    $scene = Scene::factory()->for($game)->create();
    $player = Player::factory()->for($game)->create(['stats' => ['wisdom' => 14]]);

    $action = engine(forcedRoll: 15)->submitAction($player, $scene, 'a', null);

    expect($action)
        ->roll->toBe(15)
        ->modifier->toBe(2)      // wisdom 14 => +2
        ->total->toBe(17)
        ->dc->toBe(12)
        ->success->toBeTrue();
});

it('records no roll for an option that cannot fail', function () {
    $game = Game::factory()->awaitingActions()->create();
    $scene = Scene::factory()->for($game)->create();
    $player = Player::factory()->for($game)->create();

    // Option "c" in the factory has no skill and no DC.
    $action = engine()->submitAction($player, $scene, 'c', null);

    expect($action->roll)->toBeNull()
        ->and($action->success)->toBeNull();
});

it('rolls a bare d20 with no difficulty for an improvised action', function () {
    $game = Game::factory()->awaitingActions()->create();
    $scene = Scene::factory()->for($game)->create();
    $player = Player::factory()->for($game)->create();

    $action = engine(forcedRoll: 11)->submitAction($player, $scene, null, 'I try to bribe the guard');

    expect($action)
        ->free_text->toBe('I try to bribe the guard')
        ->roll->toBe(11)
        ->dc->toBeNull()
        // The DM decides the difficulty, so success is deliberately unset here.
        ->and($action->success)->toBeNull();
});

it('rejects an action that is neither an option nor a description', function () {
    $game = Game::factory()->awaitingActions()->create();
    $scene = Scene::factory()->for($game)->create();
    $player = Player::factory()->for($game)->create();

    engine()->submitAction($player, $scene, null, '   ');
})->throws(RuntimeException::class, 'Pick an option');

it('keeps one action per player, replacing an earlier choice', function () {
    $game = Game::factory()->awaitingActions()->create();
    $scene = Scene::factory()->for($game)->create();
    $player = Player::factory()->for($game)->create();
    Player::factory()->for($game)->create();   // a second player keeps the scene open

    engine()->submitAction($player, $scene, 'a', null);
    engine()->submitAction($player, $scene, 'b', null);

    expect($scene->actions()->count())->toBe(1)
        ->and($scene->actions()->first()->option_key)->toBe('b');
});

it('will not take an action from a player who is down', function () {
    $game = Game::factory()->awaitingActions()->create();
    $scene = Scene::factory()->for($game)->create();
    $player = Player::factory()->for($game)->down()->create();

    engine()->submitAction($player, $scene, 'a', null);
})->throws(RuntimeException::class, 'down and cannot act');

it('resolves the round on its own once the last player commits', function () {
    $game = Game::factory()->awaitingActions()->create();
    $scene = Scene::factory()->for($game)->create();
    $one = Player::factory()->for($game)->create();
    $two = Player::factory()->for($game)->create();

    $engine = engine(forcedRoll: 14);

    $engine->submitAction($one, $scene, 'a', null);

    // One player left to hear from: the scene must still be open.
    expect($game->fresh()->status)->toBe(GameStatus::AwaitingActions)
        ->and($scene->fresh()->status)->toBe(SceneStatus::Open);

    $engine->submitAction($two, $scene, 'a', null);

    // Both in, so the round resolved and scene two opened (queue is sync).
    expect($scene->fresh()->status)->toBe(SceneStatus::Resolved)
        ->and($scene->fresh()->resolution)->not->toBeEmpty()
        ->and($game->fresh()->scene_number)->toBe(2)
        ->and($game->fresh()->status)->toBe(GameStatus::AwaitingActions);
});

it('does not wait on a player who is already down', function () {
    $game = Game::factory()->awaitingActions()->create();
    $scene = Scene::factory()->for($game)->create();
    $acting = Player::factory()->for($game)->create();
    Player::factory()->for($game)->down()->create();

    engine(forcedRoll: 14)->submitAction($acting, $scene, 'a', null);

    expect($scene->fresh()->status)->toBe(SceneStatus::Resolved);
});

it('applies damage, loot and conditions from the resolution', function () {
    $game = Game::factory()->awaitingActions()->create();
    $scene = Scene::factory()->for($game)->create();
    $player = Player::factory()->for($game)->create(['hp' => 12, 'max_hp' => 12]);

    // The scripted DM docks 3 HP from anyone who failed their check.
    engine(forcedRoll: 2)->submitAction($player, $scene, 'a', null);

    expect($player->fresh()->hp)->toBe(9);
    expect($game->fresh()->events()->where('type', 'damage')->exists())->toBeTrue();
});

it('lets the host force a resolution without the whole party', function () {
    $game = Game::factory()->awaitingActions()->create();
    $scene = Scene::factory()->for($game)->create();
    Player::factory()->for($game)->count(3)->create();

    engine()->closeScene($game);

    expect($scene->fresh()->status)->toBe(SceneStatus::Resolved);
});

it('broadcasts a change whenever the table moves', function () {
    Event::fake([GameUpdated::class]);

    $game = Game::factory()->create();
    engine()->join($game, 'Ana', 'Brawler');

    Event::assertDispatched(GameUpdated::class, fn ($event) => $event->reason === 'player-joined');
});

it('re-opens the scene and surfaces the reason when the storyteller fails', function () {
    $game = Game::factory()->awaitingActions()->create();
    $scene = Scene::factory()->for($game)->create(['status' => SceneStatus::Resolving]);
    Player::factory()->for($game)->create();

    engine()->recordFailure($game, 'The storyteller declined to continue.');

    // The party's actions and rolls survive, so the host can simply retry.
    expect($game->fresh()->last_error)->toContain('declined')
        ->and($game->fresh()->status)->toBe(GameStatus::AwaitingActions)
        ->and($scene->fresh()->status)->toBe(SceneStatus::Open);
});

it('ends the session and records the outcome', function () {
    $game = Game::factory()->awaitingActions()->create();

    engine()->end($game, 'victory');

    expect($game->fresh()->status)->toBe(GameStatus::Ended)
        ->and($game->fresh()->outcome)->toBe('victory');
});
