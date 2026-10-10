<?php

namespace App\Jobs;

use App\Exceptions\DungeonMasterFailed;
use App\Models\Game;
use App\Services\GameEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ResolveRound implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public Game $game) {}

    public function handle(GameEngine $engine): void
    {
        $engine->resolveRound($this->game);
    }

    public function failed(?Throwable $e): void
    {
        // Re-opens the scene so the party's actions and rolls are not lost and
        // the host can simply press resolve again.
        app(GameEngine::class)->carryOn(
            $this->game,
            $e instanceof DungeonMasterFailed
                ? $e->getMessage()
                : 'The storyteller stumbled resolving that round. Try again.',
        );
    }
}
