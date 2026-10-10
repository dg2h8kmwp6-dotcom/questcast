<?php

namespace App\Providers;

use App\Services\Narrator\Contracts\Narrator;
use App\Services\Narrator\ElevenLabsNarrator;
use App\Services\Narrator\SilentNarrator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class NarratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Narrator::class, function () {
            $driver = config('questcast.narrator.driver');

            // Falling back rather than failing keeps a fresh clone playable:
            // the story is still on screen, it just is not read aloud.
            if ($driver === 'elevenlabs' && blank(config('services.elevenlabs.key'))) {
                Log::warning('ELEVENLABS_API_KEY is not set; the story will not be read aloud.');
                $driver = 'silent';
            }

            return match ($driver) {
                'silent' => new SilentNarrator,
                'elevenlabs' => new ElevenLabsNarrator,
                default => throw new \InvalidArgumentException(
                    "Unknown narrator driver [{$driver}]."
                ),
            };
        });
    }
}
