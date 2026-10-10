<?php

namespace App\Providers;

use Anthropic\Client;
use App\Services\DungeonMaster\ClaudeDungeonMaster;
use App\Services\DungeonMaster\Contracts\DungeonMaster;
use App\Services\DungeonMaster\ScriptedDungeonMaster;
use App\Services\DungeonMaster\Transcript;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class DungeonMasterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, fn () => new Client(
            apiKey: (string) config('services.anthropic.key'),
        ));

        $this->app->singleton(DungeonMaster::class, function ($app) {
            $driver = config('questcast.dm.driver');

            // Falling back rather than failing keeps a fresh clone playable:
            // you can run a whole session before you have a key.
            if ($driver === 'claude' && blank(config('services.anthropic.key'))) {
                Log::warning('ANTHROPIC_API_KEY is not set; using the scripted dungeon master.');
                $driver = 'scripted';
            }

            return match ($driver) {
                'scripted' => new ScriptedDungeonMaster,
                'claude' => new ClaudeDungeonMaster(
                    $app->make(Client::class),
                    $app->make(Transcript::class),
                ),
                default => throw new \InvalidArgumentException(
                    "Unknown dungeon master driver [{$driver}]."
                ),
            };
        });
    }
}
