<?php

namespace App\Console\Commands\Narrator;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Lists the voices on the configured ElevenLabs account.
 *
 * Picking a storyteller is done by ear, so this exists to get you from "I want
 * a wise old man" to an ELEVENLABS_VOICE_ID without hunting through a web UI.
 */
class ListVoices extends Command
{
    protected $signature = 'narrator:voices {--search= : Only show voices whose name or description matches}';

    protected $description = 'List the voices available to the storyteller';

    public function handle(): int
    {
        $key = (string) config('services.elevenlabs.key');

        if ($key === '') {
            $this->components->error('ELEVENLABS_API_KEY is not set.');

            return self::FAILURE;
        }

        $response = Http::withHeaders(['xi-api-key' => $key])
            ->timeout(30)
            ->get('https://api.elevenlabs.io/v1/voices');

        if ($response->failed()) {
            $this->components->error("ElevenLabs returned {$response->status()}: {$response->body()}");

            return self::FAILURE;
        }

        $search = mb_strtolower((string) $this->option('search'));

        $rows = collect($response->json('voices', []))
            ->map(fn (array $voice): array => [
                'id' => $voice['voice_id'] ?? '',
                'name' => $voice['name'] ?? '',
                'description' => collect($voice['labels'] ?? [])->filter()->implode(', '),
            ])
            ->filter(fn (array $voice): bool => $search === ''
                || str_contains(mb_strtolower($voice['name'].' '.$voice['description']), $search))
            ->values();

        if ($rows->isEmpty()) {
            $this->components->warn('No voices matched.');

            return self::SUCCESS;
        }

        $this->table(['Voice ID', 'Name', 'Labels'], $rows);
        $this->newLine();
        $this->components->info('Set ELEVENLABS_VOICE_ID to the one you want to tell the story.');

        return self::SUCCESS;
    }
}
