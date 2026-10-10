<?php

use App\Livewire\Home;
use App\Models\Game;
use App\Models\Player;
use App\Services\DungeonMaster\Contracts\DungeonMaster;
use App\Services\DungeonMaster\ScriptedDungeonMaster;
use App\Services\GameEngine;
use Livewire\Livewire;

beforeEach(fn () => config()->set('questcast.dm.driver', 'scripted'));

/** A storyteller that insists on a tone of its own choosing. */
function dmChoosing(string $tone): void
{
    app()->instance(DungeonMaster::class, new class($tone) extends ScriptedDungeonMaster
    {
        public function __construct(private string $tone) {}

        public function chooseTone(Game $game): string
        {
            return $this->tone;
        }
    });
}

it('starts a game with no tone chosen', function () {
    $game = Game::start('The Lantern Road', 'Somebody has been lighting the lamps along the old cart road.');

    expect($game->tone)->toBe(Game::TONE_AUTO);
});

it('reads a tone off the premise before writing the opening scene', function () {
    $game = Game::factory()->create([
        'tone' => Game::TONE_AUTO,
        'title' => 'The Buried Gate',
        'premise' => 'The storm has not let up in a week and the pit floods faster than the crew can bail it.',
    ]);
    Player::factory()->for($game)->create();

    app(GameEngine::class)->openOpeningScene($game);

    expect($game->fresh()->tone)->toBe('tempest');
});

it('leaves a tone the host chose alone', function () {
    dmChoosing('tempest');

    $game = Game::factory()->create(['tone' => 'frostpeak']);
    Player::factory()->for($game)->create();

    app(GameEngine::class)->openOpeningScene($game);

    expect($game->fresh()->tone)->toBe('frostpeak');
});

it('falls back to a real tone when the storyteller invents one', function () {
    dmChoosing('interpretive-dance');

    $game = Game::factory()->create(['tone' => Game::TONE_AUTO]);
    Player::factory()->for($game)->create();

    app(GameEngine::class)->openOpeningScene($game);

    expect($game->fresh()->tone)->toBeIn(array_keys(config('questcast.tones')));
});

it('settles the tone only once', function () {
    dmChoosing('ruins');

    $game = Game::factory()->create(['tone' => Game::TONE_AUTO]);
    Player::factory()->for($game)->create();

    app(GameEngine::class)->openOpeningScene($game);
    expect($game->fresh()->tone)->toBe('ruins');

    // A later round must not get to re-read the room's mood.
    dmChoosing('frostpeak');
    app(GameEngine::class)->openOpeningScene($game->fresh());

    expect($game->fresh()->tone)->toBe('ruins');
});

// ---- The home page --------------------------------------------------------

it('offers an adventure off the shelf and fills the form with it', function () {
    $shelf = config('questcast.adventures');

    Livewire::test(Home::class)
        ->call('useAdventure', 2)
        ->assertSet('title', $shelf[2]['title'])
        ->assertSet('premise', $shelf[2]['premise'])
        ->assertSet('chosen', 2);
});

it('clears the decks for somebody with their own idea', function () {
    Livewire::test(Home::class)
        ->call('writeMyOwn')
        ->assertSet('title', '')
        ->assertSet('premise', '')
        ->assertSet('chosen', null)
        ->assertSet('tone', Game::TONE_AUTO);
});

it('hosts a game without the host picking a tone, and settles one in the lobby', function () {
    Livewire::test(Home::class)
        ->set('title', 'The Ninth Terrace')
        ->set('premise', 'Eight terraces climb the mountain and the ninth has started smoking.')
        ->set('tone', Game::TONE_AUTO)
        ->call('host')
        ->assertHasNoErrors()
        ->assertRedirect();

    // Planning runs the moment the game exists, and settling the tone is the
    // first thing it does, so the mood is decided before anybody presses begin.
    expect(Game::sole()->tone)->toBe('frostpeak');
});
