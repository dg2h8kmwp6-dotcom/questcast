<?php

namespace App\Jobs;

use App\Exceptions\DungeonMasterFailed;
use App\Models\Game;
use App\Services\GameEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class OpenOpeningScene implements ShouldQueue
{
    use Queueable;

    /** Two attempts: a transient API hiccup is worth one retry, a refusal is not. */
    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public Game $game) {}

    public function handle(GameEngine $engine): void
    {
        $engine->openOpeningScene($this->game);
    }

    public function failed(?Throwable $e): void
    {
        app(GameEngine::class)->recordFailure(
            $this->game,
            $e instanceof DungeonMasterFailed
                ? $e->getMessage()
                : 'The storyteller could not start this adventure. Try again.',
        );
    }
}
