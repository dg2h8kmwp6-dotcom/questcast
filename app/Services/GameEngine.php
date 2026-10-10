<?php

namespace App\Services;

use App\Enums\EventType;
use App\Enums\GameStatus;
use App\Enums\SceneStatus;
use App\Events\GameUpdated;
use App\Jobs\NarrateScene;
use App\Jobs\OpenOpeningScene;
use App\Jobs\ResolveRound;
use App\Models\Action;
use App\Models\Game;
use App\Models\Player;
use App\Models\Scene;
use App\Services\DungeonMaster\Contracts\DungeonMaster;
use App\Services\DungeonMaster\SceneDraft;
use App\Services\DungeonMaster\StateChange;
use App\Support\Dice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * All game state transitions live here.
 *
 * Livewire components and queued jobs both call into this; neither mutates the
 * game directly. That keeps the rules in one readable place and makes the whole
 * loop testable without touching HTTP or the queue.
 */
class GameEngine
{
    public function __construct(
        private readonly DungeonMaster $dm,
        private readonly Dice $dice,
    ) {}

    public function join(Game $game, string $name, string $archetype, ?string $portraitPath = null): Player
    {
        if (! $game->status->acceptsNewPlayers()) {
            throw new RuntimeException('This game has already begun.');
        }

        $stats = config("questcast.archetypes.{$archetype}");

        if (! $stats) {
            throw new RuntimeException("Unknown archetype [{$archetype}].");
        }

        if ($game->players()->count() >= (int) data_get($game->settings, 'max_players', 8)) {
            throw new RuntimeException('This table is full.');
        }

        $hp = (int) data_get($game->settings, 'starting_hp', 12)
            + (int) floor(($stats['constitution'] - 10) / 2);

        $player = $game->players()->create([
            'token' => Str::random(64),
            'name' => $name,
            'character_name' => $name,
            'archetype' => $archetype,
            'portrait_path' => $portraitPath,
            'stats' => $stats,
            'max_hp' => $hp,
            'hp' => $hp,
            'last_seen_at' => now(),
        ]);

        $game->log(EventType::Join, "{$name} the {$archetype} joins the party.", scene: null);

        GameUpdated::dispatch($game->fresh(), 'player-joined');

        return $player;
    }

    /** Host pressed "begin". The first scene is written on the queue. */
    public function begin(Game $game): void
    {
        if ($game->players()->count() === 0) {
            throw new RuntimeException('Nobody has joined yet.');
        }

        $game->transitionTo(GameStatus::Generating);

        GameUpdated::dispatch($game->fresh(), 'generating');

        OpenOpeningScene::dispatch($game);
    }

    /** Called from the queue: ask the DM for scene one and open it. */
    public function openOpeningScene(Game $game): Scene
    {
        $this->settleTone($game);

        $draft = $this->dm->openingScene($game);

        return DB::transaction(function () use ($game, $draft) {
            $scene = $this->persistScene($game, $draft, number: 1);

            $game->update(['scene_number' => 1, 'last_error' => null]);
            $game->transitionTo(GameStatus::AwaitingActions);
            $game->log(EventType::Narration, $draft->narration, scene: $scene);

            // The scene opens now; the voice catches up with it a moment later.
            NarrateScene::dispatch($scene, 'narration')->afterCommit();

            GameUpdated::dispatch($game->fresh(), 'scene-opened');

            return $scene;
        });
    }

    /**
     * Lock in one player's choice, rolling for it immediately.
     *
     * Rolling here — rather than when the round resolves — means the result is
     * fixed before the DM ever sees the action, so the narration has to live
     * with it.
     */
    public function submitAction(Player $player, Scene $scene, ?string $optionKey, ?string $freeText): Action
    {
        if (! $scene->isOpen()) {
            throw new RuntimeException('That scene is no longer taking actions.');
        }

        if (! $player->isAlive()) {
            throw new RuntimeException('You are down and cannot act.');
        }

        $freeText = trim((string) $freeText) ?: null;
        $option = $optionKey ? $scene->optionFor($optionKey) : null;

        if (! $option && ! $freeText) {
            throw new RuntimeException('Pick an option or describe what you do.');
        }

        // A typed action has no pre-set difficulty, so we hand the DM a bare
        // d20 and let it judge; a listed option carries its own skill and DC.
        $skill = $option['skill'] ?? null;
        $dc = $option['dc'] ?? null;
        $modifier = $option ? $player->modifierFor($skill) : 0;

        $check = ($option && ! $skill)
            ? null                                  // an option that cannot fail
            : $this->dice->check($modifier, $dc);

        $action = $scene->actions()->updateOrCreate(
            ['player_id' => $player->id],
            [
                'option_key' => $option['key'] ?? null,
                'free_text' => $freeText,
                'skill' => $skill,
                'dc' => $dc,
                'roll' => $check['roll'] ?? null,
                'modifier' => $check['modifier'] ?? 0,
                'total' => $check['total'] ?? null,
                'success' => $check === null ? null : ($dc === null ? null : $check['success']),
                'critical' => $check['critical'] ?? false,
            ],
        );

        $player->update(['last_seen_at' => now()]);

        if ($summary = $action->fresh()->rollSummary()) {
            $game = $scene->game;
            $game->log(
                EventType::Roll,
                "{$player->displayName()}: {$summary}",
                ['player_id' => $player->id],
                $scene,
            );
        }

        GameUpdated::dispatch($scene->game->fresh(), 'action-submitted');

        // The round resolves itself the moment the last living player commits.
        if ($scene->game->fresh()->partyHasDecided()) {
            $this->closeScene($scene->game->fresh());
        }

        return $action;
    }

    /** Stop taking actions and queue the DM's resolution. */
    public function closeScene(Game $game): void
    {
        $scene = $game->currentScene;

        if (! $scene || ! $scene->isOpen()) {
            return;
        }

        $scene->update(['status' => SceneStatus::Resolving]);
        $game->transitionTo(GameStatus::Resolving);

        GameUpdated::dispatch($game->fresh(), 'resolving');

        ResolveRound::dispatch($game);
    }

    /** Called from the queue: narrate the round and open the next scene. */
    public function resolveRound(Game $game): void
    {
        $scene = $game->currentScene;

        if (! $scene) {
            return;
        }

        $game->load(['players', 'scenes']);
        $scene->load(['actions.player']);

        $result = $this->dm->resolveRound($game, $scene);

        DB::transaction(function () use ($game, $scene, $result) {
            $scene->update([
                'status' => SceneStatus::Resolved,
                'resolution' => $result->resolution,
                'resolved_at' => now(),
            ]);

            $game->log(EventType::Resolution, $result->resolution, scene: $scene);

            NarrateScene::dispatch($scene, 'resolution')->afterCommit();

            $this->applyStateChanges($game, $scene, $result->stateChanges);

            if ($result->gameOver || ! $result->nextScene) {
                $this->end($game, $result->outcome ?? 'ongoing');

                return;
            }

            $number = $scene->number + 1;
            $next = $this->persistScene($game, $result->nextScene, $number);

            $game->update(['scene_number' => $number, 'last_error' => null]);
            $game->transitionTo(GameStatus::AwaitingActions);
            $game->log(EventType::Narration, $result->nextScene->narration, scene: $next);

            NarrateScene::dispatch($next, 'narration')->afterCommit();

            GameUpdated::dispatch($game->fresh(), 'scene-opened');
        });
    }

    /**
     * Settle the register before the storyteller is asked for anything.
     *
     * A host who did not pick a tone gets one read off their own premise, once,
     * and it is written down. Deciding it here rather than inside the prompt is
     * what keeps every later round building the identical, cacheable system
     * prompt instead of re-reading the room's mood each time.
     */
    private function settleTone(Game $game): void
    {
        if ($game->tone !== Game::TONE_AUTO) {
            return;
        }

        $known = array_keys(config('questcast.tones'));
        $chosen = $this->dm->chooseTone($game);

        $game->update([
            'tone' => in_array($chosen, $known, true) ? $chosen : (string) reset($known),
        ]);
    }

    /** @param array<int,StateChange> $changes */
    private function applyStateChanges(Game $game, Scene $scene, array $changes): void
    {
        $players = $game->players->keyBy('id');

        foreach ($changes as $change) {
            $player = $players->get($change->playerId);

            if (! $player) {
                continue;   // the DM referenced an id outside the roster
            }

            $wasAlive = $player->isAlive();

            if ($change->hpDelta !== 0) {
                $player->applyDamage(-$change->hpDelta);

                $game->log(
                    EventType::Damage,
                    sprintf(
                        '%s %s %d HP (now %d/%d).%s',
                        $player->displayName(),
                        $change->hpDelta < 0 ? 'takes' : 'recovers',
                        abs($change->hpDelta),
                        $player->hp,
                        $player->max_hp,
                        $change->note ? ' '.$change->note : '',
                    ),
                    ['player_id' => $player->id, 'hp_delta' => $change->hpDelta],
                    $scene,
                );
            }

            if ($change->addItems !== []) {
                $player->giveItems($change->addItems);
                $game->log(
                    EventType::Loot,
                    $player->displayName().' gains '.implode(', ', $change->addItems).'.',
                    ['player_id' => $player->id],
                    $scene,
                );
            }

            if ($change->removeItems !== []) {
                $player->takeItems($change->removeItems);
            }

            $player->update(['conditions' => $change->conditions]);

            if ($wasAlive && ! $player->fresh()->isAlive()) {
                $game->log(
                    EventType::Death,
                    $player->displayName().' goes down.',
                    ['player_id' => $player->id],
                    $scene,
                );
            }
        }
    }

    public function end(Game $game, ?string $outcome = null): void
    {
        $game->update(['outcome' => $outcome]);
        $game->transitionTo(GameStatus::Ended);
        $game->log(EventType::System, match ($outcome) {
            'victory' => 'The party prevails. The tale is told.',
            'defeat' => 'The party falls. The tale ends here.',
            default => 'The session is closed.',
        });

        GameUpdated::dispatch($game->fresh(), 'ended');
    }

    /** Surface a DM failure on the host screen instead of swallowing it. */
    public function recordFailure(Game $game, string $message): void
    {
        $game->update(['last_error' => $message, 'thinking_since' => null]);

        $scene = $game->currentScene;

        // Re-open the scene so the host can retry the same round.
        if ($scene && $scene->status === SceneStatus::Resolving) {
            $scene->update(['status' => SceneStatus::Open]);
            $game->transitionTo(GameStatus::AwaitingActions);
        } elseif ($game->status === GameStatus::Generating) {
            $game->transitionTo(GameStatus::Lobby);
        }

        GameUpdated::dispatch($game->fresh(), 'error');
    }

    private function persistScene(Game $game, SceneDraft $draft, int $number): Scene
    {
        return $game->scenes()->updateOrCreate(
            ['number' => $number],
            [
                'status' => SceneStatus::Open,
                'narration' => $draft->narration,
                'options' => $draft->options,
                'image_prompt' => $draft->imagePrompt,
            ],
        );
    }
}
