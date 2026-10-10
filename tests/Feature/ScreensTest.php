<?php

use App\Enums\GameStatus;
use App\Livewire\Home;
use App\Livewire\Host\Screen;
use App\Livewire\Player\Table;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Player;
use App\Models\Scene;
use Livewire\Livewire;

beforeEach(fn () => config()->set('questcast.dm.driver', 'scripted'));

it('shows the landing page', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Islands to cross', false)
        ->assertSee('Join the party');
});

it('creates a game and hands the host screen its secret', function () {
    Livewire::test(Home::class)
        ->set('title', 'The Drowned Cathedral')
        ->set('premise', 'The tide went out and did not come back in, and something is walking up the seabed.')
        ->set('tone', 'ruins')
        ->call('host')
        ->assertRedirect();

    $game = Game::sole();

    expect($game->title)->toBe('The Drowned Cathedral')
        ->and($game->tone)->toBe('ruins')
        ->and($game->status)->toBe(GameStatus::Lobby);

    expect(session("host_token.{$game->code}"))->toBe($game->host_token);
});

it('rejects a premise too thin to build on', function () {
    Livewire::test(Home::class)
        ->set('premise', 'A dragon.')
        ->call('host')
        ->assertHasErrors(['premise']);
});

it('rejects an unknown tone', function () {
    Livewire::test(Home::class)
        ->set('tone', 'interpretive-dance')
        ->call('host')
        ->assertHasErrors(['tone']);
});

it('sends a player to the table when the code is real', function () {
    $game = Game::factory()->create();

    Livewire::test(Home::class)
        ->set('code', strtolower($game->code))
        ->call('joinExisting')
        ->assertRedirect(route('play', $game->code));
});

it('says so when the code matches nothing', function () {
    Livewire::test(Home::class)
        ->set('code', 'ZZZZZ')
        ->call('joinExisting')
        ->assertHasErrors(['code']);
});

it('keeps the host screen away from anyone without the secret', function () {
    $game = Game::factory()->create();

    $this->get(route('host', $game->code))->assertForbidden();
});

it('shows the join code and a QR once the host is verified', function () {
    $game = Game::factory()->create();

    $this->withSession(["host_token.{$game->code}" => $game->host_token])
        ->get(route('host', $game->code))
        ->assertOk()
        ->assertSee($game->code)
        ->assertSee('Join code')
        ->assertSee('<svg', false);
});

it('will not let the host begin with nobody seated', function () {
    $game = Game::factory()->create();

    session(["host_token.{$game->code}" => $game->host_token]);

    Livewire::test(Screen::class, ['game' => $game])
        ->call('begin')
        ->assertHasErrors('control');
});

it('runs the whole first round from the host screen', function () {
    $game = Game::factory()->create();
    Player::factory()->for($game)->create();

    session(["host_token.{$game->code}" => $game->host_token]);

    Livewire::test(Screen::class, ['game' => $game])
        ->call('begin')
        ->assertHasNoErrors();

    expect($game->fresh()->status)->toBe(GameStatus::AwaitingActions)
        ->and($game->fresh()->scene_number)->toBe(1);
});

it('keeps enough campaign log for several rounds, oldest entry first', function () {
    $game = Game::factory()->create();

    foreach (range(1, 45) as $n) {
        GameEvent::factory()->for($game)->create(['body' => "Entry {$n}."]);
    }

    session(["host_token.{$game->code}" => $game->host_token]);

    $component = Livewire::test(Screen::class, ['game' => $game])
        ->assertDontSee('Entry 5.')
        ->assertSee('Entry 6.')
        ->assertSee('Entry 45.');

    // The newest entry sits at the bottom, which is where the screen pins it.
    $html = $component->html();

    expect(strpos($html, 'Entry 6.'))->toBeLessThan(strpos($html, 'Entry 45.'));
});

it('lets a phone join and remembers the character in the session', function () {
    $game = Game::factory()->create();

    $component = Livewire::test(Table::class, ['game' => $game])
        ->set('name', 'Ana')
        ->set('archetype', 'Scout')
        ->call('join')
        ->assertHasNoErrors();

    $player = $game->players()->sole();

    expect($player->name)->toBe('Ana')
        ->and($player->archetype)->toBe('Scout');

    $component->assertSee('You&rsquo;re in', false);
});

it('joins with the default character when nobody picks one', function () {
    $game = Game::factory()->create();

    // A player who just types a name and taps join must not hit a validation
    // wall because the default archetype no longer exists.
    Livewire::test(Table::class, ['game' => $game])
        ->set('name', 'Ana')
        ->call('join')
        ->assertHasNoErrors();

    expect($game->players()->sole()->archetype)
        ->toBeIn(array_keys(config('questcast.archetypes')));
});

it('stops two phones from claiming the same name', function () {
    $game = Game::factory()->create();
    Player::factory()->for($game)->create(['name' => 'Ana']);

    Livewire::test(Table::class, ['game' => $game])
        ->set('name', 'Ana')
        ->call('join')
        ->assertHasErrors(['name']);
});

it('turns a latecomer away once the game has started', function () {
    $game = Game::factory()->awaitingActions()->create();

    Livewire::test(Table::class, ['game' => $game])
        ->assertSee('already underway');
});

it('shows the options and takes a choice', function () {
    $game = Game::factory()->awaitingActions()->create();
    $scene = Scene::factory()->for($game)->create();
    $player = Player::factory()->for($game)->create();
    Player::factory()->for($game)->create();   // keeps the scene open

    session(["player_token.{$game->code}" => $player->token]);

    Livewire::test(Table::class, ['game' => $game])
        ->assertSee('Press forward carefully')
        ->call('choose', 'a')
        ->assertHasNoErrors()
        ->assertSee('You chose');

    expect($scene->actions()->where('player_id', $player->id)->exists())->toBeTrue();
});

it('lets a player type their own action', function () {
    $game = Game::factory()->awaitingActions()->create();
    $scene = Scene::factory()->for($game)->create();
    $player = Player::factory()->for($game)->create();
    Player::factory()->for($game)->create();

    session(["player_token.{$game->code}" => $player->token]);

    Livewire::test(Table::class, ['game' => $game])
        ->set('freeText', 'I climb the rock face to get a better look')
        ->call('improvise')
        ->assertHasNoErrors();

    expect($scene->actions()->sole()->free_text)->toContain('climb the rock face');
});

it('lets a player change their mind while the scene is open', function () {
    $game = Game::factory()->awaitingActions()->create();
    $scene = Scene::factory()->for($game)->create();
    $player = Player::factory()->for($game)->create();
    Player::factory()->for($game)->create();

    session(["player_token.{$game->code}" => $player->token]);

    Livewire::test(Table::class, ['game' => $game])
        ->call('choose', 'a')
        ->call('rethink')
        ->assertSee('Press forward carefully');

    expect($scene->actions()->count())->toBe(0);
});

it('tells a downed player there is nothing to do', function () {
    $game = Game::factory()->awaitingActions()->create();
    Scene::factory()->for($game)->create();
    $player = Player::factory()->for($game)->down()->create();

    session(["player_token.{$game->code}" => $player->token]);

    Livewire::test(Table::class, ['game' => $game])
        ->assertSee('You are down');
});
