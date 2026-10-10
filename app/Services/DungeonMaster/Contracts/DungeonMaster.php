<?php

namespace App\Services\DungeonMaster\Contracts;

use App\Models\Game;
use App\Models\Scene;
use App\Services\DungeonMaster\RoundResult;
use App\Services\DungeonMaster\SceneDraft;

interface DungeonMaster
{
    /** Write the opening scene for a game that has only a premise and a party. */
    public function openingScene(Game $game): SceneDraft;

    /**
     * Narrate the outcome of everyone's actions and set up what comes next.
     *
     * The scene's actions already carry their dice results. The DM's job is to
     * justify those results in fiction, not to decide them.
     */
    public function resolveRound(Game $game, Scene $scene): RoundResult;
}
