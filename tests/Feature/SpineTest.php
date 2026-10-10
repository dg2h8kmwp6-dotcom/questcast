<?php

use App\Enums\GameStatus;
use App\Enums\SceneStatus;
use App\Jobs\PlanAdventure;
use App\Livewire\Home;
use App\Models\Game;
use App\Models\Player;
use App\Models\Scene;
use App\Services\DungeonMaster\Contracts\DungeonMaster;
use App\Services\DungeonMaster\ScriptedDungeonMaster;
use App\Services\DungeonMaster\Transcript;
use App\Services\GameEngine;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(fn () => config()->set('questcast.dm.driver', 'scripted'));

/** A game that already has its arc laid out and a round going wrong. */
function gameMidRound(int $beats = 3): array
{
    $game = Game::factory()->awaitingActions()->create();
    Player::factory()->for($game)->create();

    app(GameEngine::class)->planAdventure($game);

    $scene = Scene::factory()->for($game)->create([
        'number' => 1,
        'status' => SceneStatus::Resolving,
    ]);

    $game->update(['scene_number' => 1, 'status' => GameStatus::Resolving]);

    return [$game->fresh(), $scene];
}

it('lays out the arc before anybody acts', function () {
    $game = Game::factory()->create();

    app(GameEngine::class)->planAdventure($game);

    $game->refresh();

    expect($game->spine)->toHaveCount(config('questcast.story.spine'))
        ->and($game->spine_used)->toBe(0)
        ->and($game->spine[0]['narration'])->not->toBeEmpty()
        ->and($game->spine[0]['bridge'])->not->toBeEmpty();
});

it('plans an adventure only once', function () {
    $game = Game::factory()->create();
    $engine = app(GameEngine::class);

    $engine->planAdventure($game);
    $first = $game->fresh()->spine;

    // A second pass must not spend another call or rewrite the arc the prompt
    // has already been caching.
    app()->instance(DungeonMaster::class, new class extends ScriptedDungeonMaster
    {
        public function planAdventure(Game $game, int $beats): array
        {
            throw new RuntimeException('should not be asked again');
        }
    });

    $engine->planAdventure($game->fresh());

    expect($game->fresh()->spine)->toBe($first);
});

it('queues the planning as soon as a game is created', function () {
    Queue::fake();

    Livewire::test(Home::class)
        ->set('title', 'The Lantern Road')
        ->set('premise', 'Somebody has been lighting the lamps along the old cart road through the canopy.')
        ->call('host');

    Queue::assertPushed(PlanAdventure::class);
});

// ---- Falling forward ------------------------------------------------------

it('carries the story onto the next planned beat when a round fails', function () {
    [$game, $scene] = gameMidRound();

    app(GameEngine::class)->carryOn($game, 'the storyteller stumbled');

    $game->refresh();

    expect($game->last_error)->toBeNull()
        ->and($game->status)->toBe(GameStatus::AwaitingActions)
        ->and($game->scene_number)->toBe(2)
        ->and($game->spine_used)->toBe(1);

    // The round the party actually played still gets an outcome.
    expect($scene->fresh()->status)->toBe(SceneStatus::Resolved)
        ->and($scene->fresh()->resolution)->not->toBeEmpty();

    // And the beat is a real, playable scene.
    $next = $game->currentScene;
    expect($next->number)->toBe(2)
        ->and($next->narration)->not->toBeEmpty()
        ->and($next->options)->not->toBeEmpty()
        ->and($next->status)->toBe(SceneStatus::Open);
});

it('opens on a planned beat when the opening scene itself fails', function () {
    $game = Game::factory()->create();
    Player::factory()->for($game)->create();

    app(GameEngine::class)->planAdventure($game);
    $game->update(['status' => GameStatus::Generating]);

    // Nothing has been played yet, so there is no scene to resolve — only a
    // first one to conjure out of the arc.
    app(GameEngine::class)->carryOn($game->fresh(), 'the opening would not come');

    $game->refresh();

    expect($game->last_error)->toBeNull()
        ->and($game->status)->toBe(GameStatus::AwaitingActions)
        ->and($game->scene_number)->toBe(1)
        ->and($game->spine_used)->toBe(1)
        ->and($game->currentScene->narration)->toBe($game->spine[0]['narration']);
});

it('resolves the dying scene with the beat the planner wrote for it', function () {
    [$game, $scene] = gameMidRound();

    $bridge = $game->spine[0]['bridge'];

    app(GameEngine::class)->carryOn($game, 'the storyteller stumbled');

    expect($scene->fresh()->resolution)->toBe($bridge);
});

it('spends one beat per stumble', function () {
    [$game] = gameMidRound();

    $engine = app(GameEngine::class);

    foreach (range(1, 3) as $round) {
        $engine->carryOn($game->fresh(), 'again');

        $game->refresh();
        $game->currentScene->update(['status' => SceneStatus::Resolving]);
        $game->update(['status' => GameStatus::Resolving]);
    }

    expect($game->fresh()->spine_used)->toBe(3);
});

it('falls back to the error bar once the net has run out', function () {
    [$game] = gameMidRound();

    // Pretend every planned beat has already been spent.
    $game->update(['spine_used' => count($game->spine)]);

    app(GameEngine::class)->carryOn($game->fresh(), 'nothing left to fall onto');

    $game->refresh();

    expect($game->last_error)->toBe('nothing left to fall onto')
        ->and($game->spineRemaining())->toBe(0);
});

it('starts the game even when the arc could not be planned', function () {
    app()->instance(DungeonMaster::class, new class extends ScriptedDungeonMaster
    {
        public function planAdventure(Game $game, int $beats): array
        {
            throw new RuntimeException('the planner is down');
        }
    });

    $game = Game::factory()->create();
    Player::factory()->for($game)->create();

    app(GameEngine::class)->openOpeningScene($game);

    $game->refresh();

    expect($game->hasSpine())->toBeFalse()
        ->and($game->status)->toBe(GameStatus::AwaitingActions)
        ->and($game->scene_number)->toBe(1);
});

it('hands the planned arc to the storyteller as context', function () {
    $game = Game::factory()->create();
    app(GameEngine::class)->planAdventure($game);

    $system = app(Transcript::class)->system($game->fresh());

    expect($system[0]['text'])->toContain('The arc you are heading for')
        ->toContain($game->fresh()->spine[0]['narration']);
});
