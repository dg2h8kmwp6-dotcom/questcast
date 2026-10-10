<?php

namespace App\Services\Narrator;

use App\Services\Narrator\Contracts\Narrator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The storyteller's voice.
 *
 * Speech is rendered off the queue and cached on disk, so latency here costs
 * the room nothing and a passage is only ever paid for once. Nothing in this
 * class is allowed to throw: a scene that fails to find its voice still shows
 * its text, and the session carries on in silence.
 */
class ElevenLabsNarrator implements Narrator
{
    private const ENDPOINT = 'https://api.elevenlabs.io/v1/text-to-speech';

    public function speak(string $passage): ?Utterance
    {
        $passage = trim($passage);

        if ($passage === '') {
            return null;
        }

        $voice = (string) config('questcast.narrator.voice');

        if ($voice === '') {
            Log::warning('No narrator voice is configured; the story will be read in silence.');

            return null;
        }

        try {
            $response = Http::withHeaders([
                'xi-api-key' => (string) config('services.elevenlabs.key'),
                'Accept' => 'audio/mpeg',
            ])
                ->timeout((int) config('questcast.narrator.timeout'))
                ->retry(2, 500, throw: false)
                ->post(self::ENDPOINT."/{$voice}?output_format=".config('questcast.narrator.format'), [
                    'text' => $passage,
                    'model_id' => config('questcast.narrator.model'),
                    'voice_settings' => config('questcast.narrator.voice_settings'),
                ]);
        } catch (Throwable $e) {
            Log::warning('The narrator could not be reached.', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            // The body of a failed call is JSON, not audio, and usually says
            // something useful like "quota exceeded".
            Log::warning('The narrator refused to speak.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $audio = $response->body();

        return $audio === '' ? null : new Utterance($audio);
    }
}
