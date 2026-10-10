<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\GameEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lay out the spine of the adventure while the party is still arriving.
 *
 * Queued the moment a game is created, which turns the lobby wait into useful
 * time: by the time the host presses begin the arc usually already exists, and
 * with it the net that keeps a failed round from stopping the session.
 */
class PlanAdventure implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public Game $game) {}

    public function handle(GameEngine $engine): void
    {
        $engine->planAdventure($this->game);
    }

    public function failed(?Throwable $e): void
    {
        // A spine is a safety net, never a prerequisite. Without one the game
        // plays exactly as it did before this existed, so the room is told
        // nothing and the host is asked for nothing.
        Log::warning('Could not lay out the adventure in advance.', [
            'game_id' => $this->game->id,
            'error' => $e?->getMessage(),
        ]);
    }
}
