<?php

use App\Jobs\NarrateScene;
use App\Livewire\Host\Screen;
use App\Models\Game;
use App\Models\Player;
use App\Models\Scene;
use App\Services\GameEngine;
use App\Services\Narrator\Contracts\Narrator;
use App\Services\Narrator\ElevenLabsNarrator;
use App\Services\Narrator\SilentNarrator;
use App\Services\Narrator\Utterance;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('questcast.dm.driver', 'scripted');
    config()->set('questcast.narrator.disk', 'narration');
    config()->set('questcast.narrator.voice', 'wise-old-man');
    config()->set('services.elevenlabs.key', 'test-key');

    Storage::fake('narration');
});

/** Swap in a narrator that always has something to say. */
function speakingNarrator(string $audio = 'MP3'): void
{
    app()->instance(Narrator::class, new class($audio) implements Narrator
    {
        public function __construct(private string $audio) {}

        public function speak(string $passage): ?Utterance
        {
            return new Utterance($this->audio);
        }
    });
}

// ---- The ElevenLabs driver ------------------------------------------------

it('turns a passage into audio', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response('MP3-BYTES', 200)]);

    $utterance = (new ElevenLabsNarrator)->speak('The tide went out and did not come back in.');

    expect($utterance)->toBeInstanceOf(Utterance::class)
        ->and($utterance->audio)->toBe('MP3-BYTES')
        ->and($utterance->contentType)->toBe('audio/mpeg');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'wise-old-man')
        && $request['text'] === 'The tide went out and did not come back in.'
        && $request->hasHeader('xi-api-key', 'test-key'));
});

it('stays quiet rather than throwing when the voice provider fails', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response('{"detail":"quota exceeded"}', 429)]);

    expect((new ElevenLabsNarrator)->speak('Anything at all.'))->toBeNull();
});

it('stays quiet when no voice has been chosen', function () {
    config()->set('questcast.narrator.voice', '');
    Http::fake();

    expect((new ElevenLabsNarrator)->speak('Anything at all.'))->toBeNull();

    Http::assertNothingSent();
});

it('falls back to the silent narrator when there is no key', function () {
    config()->set('services.elevenlabs.key', null);
    app()->forgetInstance(Narrator::class);

    expect(app(Narrator::class))->toBeInstanceOf(SilentNarrator::class);
});

// ---- Giving a scene its voice ---------------------------------------------

it('stores the spoken passage and records where it lives', function () {
    speakingNarrator('SPOKEN');

    $scene = Scene::factory()->create(['narration' => 'The stair opens onto a terrace of still pools.']);

    (new NarrateScene($scene, 'narration'))->handle(app(Narrator::class));

    $path = $scene->fresh()->narration_audio_path;

    expect($path)->toContain('narration/')->toEndWith('.mp3');
    Storage::disk('narration')->assertExists($path);
    expect(Storage::disk('narration')->get($path))->toBe('SPOKEN');
});

it('leaves the scene alone when the storyteller has nothing to say', function () {
    app()->instance(Narrator::class, new SilentNarrator);

    $scene = Scene::factory()->create(['narration' => 'The stair opens onto a terrace.']);

    (new NarrateScene($scene, 'narration'))->handle(app(Narrator::class));

    expect($scene->fresh()->narration_audio_path)->toBeNull();
});

it('pays for the same words only once', function () {
    $calls = 0;

    app()->instance(Narrator::class, new class($calls) implements Narrator
    {
        public int $calls = 0;

        public function __construct(public int $ignored) {}

        public function speak(string $passage): ?Utterance
        {
            $this->calls++;

            return new Utterance('SPOKEN');
        }
    });

    $narrator = app(Narrator::class);
    $words = 'A rope bridge crosses the gorge in three spans.';

    $first = Scene::factory()->create(['narration' => $words]);
    $second = Scene::factory()->create(['narration' => $words]);

    (new NarrateScene($first, 'narration'))->handle($narrator);
    (new NarrateScene($second, 'narration'))->handle($narrator);

    expect($narrator->calls)->toBe(1)
        ->and($second->fresh()->narration_audio_path)
        ->toBe($first->fresh()->narration_audio_path);
});

it('does not read a passage that already has a voice', function () {
    app()->instance(Narrator::class, new SilentNarrator);

    $scene = Scene::factory()->create([
        'narration' => 'Already spoken.',
        'narration_audio_path' => 'narration/kept.mp3',
    ]);

    (new NarrateScene($scene, 'narration'))->handle(app(Narrator::class));

    expect($scene->fresh()->narration_audio_path)->toBe('narration/kept.mp3');
});

it('asks for the story to be read aloud when a scene opens', function () {
    Queue::fake();

    $game = Game::factory()->create();
    Player::factory()->for($game)->create();

    app(GameEngine::class)->openOpeningScene($game);

    Queue::assertPushed(NarrateScene::class, fn (NarrateScene $job) => $job->passage === 'narration'
        && $job->scene->number === 1);
});

// ---- What the screen hands to the browser ---------------------------------

it('offers the last two scenes to be spoken, oldest passage first', function () {
    $game = Game::factory()->awaitingActions()->create();

    $first = Scene::factory()->for($game)->create([
        'number' => 1,
        'narration_audio_path' => 'narration/one.mp3',
        'resolution' => 'You get the sluices open.',
        'resolution_audio_path' => 'narration/one-res.mp3',
    ]);
    $second = Scene::factory()->for($game)->create([
        'number' => 2,
        'narration_audio_path' => 'narration/two.mp3',
    ]);

    session(["host_token.{$game->code}" => $game->host_token]);

    $passages = Livewire::test(Screen::class, ['game' => $game])
        ->instance()
        ->voiceOver;

    expect(array_column($passages, 'key'))->toBe([
        "{$first->id}-narration",
        "{$first->id}-resolution",
        "{$second->id}-narration",
    ]);
});

it('offers nothing for passages that have no audio yet', function () {
    $game = Game::factory()->awaitingActions()->create();
    Scene::factory()->for($game)->create(['number' => 1]);

    session(["host_token.{$game->code}" => $game->host_token]);

    expect(Livewire::test(Screen::class, ['game' => $game])->instance()->voiceOver)->toBe([]);
});
