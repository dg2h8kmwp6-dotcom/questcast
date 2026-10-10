<?php

use App\Livewire\Player\Table;
use App\Models\Game;
use App\Models\Player;
use App\Support\Portrait;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('questcast.dm.driver', 'scripted');
    config()->set('questcast.portraits.disk', 'portraits');
    Storage::fake('portraits');
});

/** A stand-in for a phone photo: wider than it is tall, like a landscape shot. */
function snapshot(int $w = 1200, int $h = 900): UploadedFile
{
    return UploadedFile::fake()->image('selfie.jpg', $w, $h);
}

it('stores a portrait as a small square jpeg', function () {
    $path = Portrait::store(snapshot());

    Storage::disk('portraits')->assertExists($path);
    expect($path)->toEndWith('.jpg');

    $size = getimagesizefromstring(Storage::disk('portraits')->get($path));

    expect($size[0])->toBe(Portrait::SIZE)
        ->and($size[1])->toBe(Portrait::SIZE)
        ->and($size['mime'])->toBe('image/jpeg');
});

it('squares up a tall photo without distorting it', function () {
    $path = Portrait::store(snapshot(600, 1600));
    $size = getimagesizefromstring(Storage::disk('portraits')->get($path));

    expect($size[0])->toBe($size[1]);
});

it('rejects a file that is not a readable image', function () {
    Portrait::store(UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf'));
})->throws(RuntimeException::class);

it('attaches the portrait to the player who joined', function () {
    $game = Game::factory()->create();

    Livewire::test(Table::class, ['game' => $game])
        ->set('photo', snapshot())
        ->set('name', 'Ana')
        ->call('join')
        ->assertHasNoErrors();

    $player = $game->players()->sole();

    expect($player->hasPortrait())->toBeTrue();
    Storage::disk('portraits')->assertExists($player->portrait_path);
});

it('lets a player join without a photo at all', function () {
    $game = Game::factory()->create();

    Livewire::test(Table::class, ['game' => $game])
        ->set('name', 'Ben')
        ->call('join')
        ->assertHasNoErrors();

    $player = $game->players()->sole();

    expect($player->hasPortrait())->toBeFalse()
        ->and($player->portraitUrl())->toBeNull()
        ->and($player->initials())->toBe('B');
});

it('turns away a file that is not an image', function () {
    $game = Game::factory()->create();

    Livewire::test(Table::class, ['game' => $game])
        ->set('photo', UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4'))
        ->assertHasErrors(['photo']);
});

it('turns away a photo that is too large', function () {
    $game = Game::factory()->create();

    Livewire::test(Table::class, ['game' => $game])
        ->set('photo', UploadedFile::fake()->create('huge.jpg', 9000, 'image/jpeg'))
        ->assertHasErrors(['photo']);
});

it('does not leave an orphaned portrait when the seat is taken', function () {
    $game = Game::factory()->create();
    Player::factory()->for($game)->create(['name' => 'Ana']);

    Livewire::test(Table::class, ['game' => $game])
        ->set('photo', snapshot())
        ->set('name', 'Ana')
        ->call('join')
        ->assertHasErrors(['name']);

    expect(Storage::disk('portraits')->allFiles())->toBeEmpty();
});

it('shows a face on the shared screen once a player has one', function () {
    $game = Game::factory()->create();
    $player = Player::factory()->for($game)->create(['portrait_path' => 'portraits/x.jpg']);

    expect($player->portraitUrl())->toContain('portraits/x.jpg');
});
