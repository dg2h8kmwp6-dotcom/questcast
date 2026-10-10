<?php

namespace App\Jobs;

use App\Events\GameUpdated;
use App\Models\Scene;
use App\Services\Narrator\Contracts\Narrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Give a passage of story its voice.
 *
 * This runs behind the scene rather than in front of it: the text is already
 * on the big screen by the time this starts, and the audio joins it a moment
 * later. Nothing here is allowed to disturb the game — a passage that cannot
 * be voiced is simply read off the screen instead.
 */
class NarrateScene implements ShouldQueue
{
    use Queueable;

    /** Every configured output format is an MP3 variant. */
    private const EXTENSION = 'mp3';

    /** @var array<string, string> passage name => column it is stored in */
    private const COLUMNS = [
        'narration' => 'narration_audio_path',
        'resolution' => 'resolution_audio_path',
    ];

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public Scene $scene, public string $passage) {}

    public function handle(Narrator $narrator): void
    {
        $column = self::COLUMNS[$this->passage]
            ?? throw new \InvalidArgumentException("There is no [{$this->passage}] to read aloud.");

        $scene = $this->scene->fresh();
        $text = trim((string) $scene?->{$this->passage});

        // Nothing written yet, or somebody already voiced it.
        if (! $scene || $text === '' || filled($scene->{$column})) {
            return;
        }

        $disk = Storage::disk(config('questcast.narrator.disk'));
        $path = $this->pathFor($text);

        // The same words in the same voice are the same audio, so a retry —
        // or a replayed scene — costs nothing the second time.
        if (! $disk->exists($path)) {
            $utterance = $narrator->speak($text);

            if (! $utterance) {
                return;
            }

            $disk->put($path, $utterance->audio, [
                'ContentType' => $utterance->contentType,
                'CacheControl' => 'public, max-age=31536000, immutable',
            ]);
        }

        $scene->update([$column => $path]);

        GameUpdated::dispatch($scene->game->fresh(), 'voice-ready');
    }

    /**
     * Content-addressed, and keyed by the voice as well as the words, so that
     * changing storyteller does not replay the old one.
     */
    private function pathFor(string $text): string
    {
        $fingerprint = hash('sha256', implode('|', [
            $text,
            (string) config('questcast.narrator.voice'),
            (string) config('questcast.narrator.model'),
        ]));

        return 'narration/'.$fingerprint.'.'.self::EXTENSION;
    }

    public function failed(?Throwable $e): void
    {
        // The story is on screen regardless; a lost voice is not worth an
        // error bar in front of the room.
        Log::warning('A passage could not be read aloud.', [
            'scene_id' => $this->scene->id,
            'passage' => $this->passage,
            'error' => $e?->getMessage(),
        ]);
    }
}
