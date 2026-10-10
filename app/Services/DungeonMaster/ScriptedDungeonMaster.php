<?php

namespace App\Services\DungeonMaster;

use App\Models\Game;
use App\Models\Scene;
use App\Services\DungeonMaster\Contracts\DungeonMaster;

/**
 * A DM that needs no API key.
 *
 * It exists for two reasons: the test suite must not spend money or depend on
 * the network, and you should be able to click through a whole session locally
 * before wiring up credentials. The prose is deliberately generic — it proves
 * the plumbing, not the storytelling.
 */
class ScriptedDungeonMaster implements DungeonMaster
{
    private const BEATS = [
        'The jetty gives way to white sand and, past it, a staircase cut straight into the cliff. Something up there is turning: you can hear brass teeth counting out a rhythm.',
        'The stair opens onto a terrace of still pools, each one draining into the next through a sluice somebody closed a very long time ago.',
        'A rope bridge crosses the gorge in three spans. The middle span is missing, and the mist below is going up, not down.',
        'The cart track runs into the mountain and keeps going. A lantern is burning at the far end, which means somebody filled it this week.',
        'The gallery floods to your knees and then to your waist. Ahead, a door of green glass glows from the other side.',
    ];

    /**
     * Words that give a premise away, for a DM that cannot read.
     *
     * Crude on purpose: this proves the plumbing so the suite can run offline,
     * and anything subtler would be pretending to be the real classifier.
     *
     * @var array<string, array<int, string>>
     */
    private const TONE_CUES = [
        'tempest' => ['tide', 'storm', 'drowned', 'sunken', 'seabed', 'wave'],
        'frostpeak' => ['snow', 'ice', 'frost', 'summit', 'mountain', 'chimney'],
        'canopy' => ['jungle', 'canopy', 'vine', 'rope bridge', 'cart road', 'drum'],
        'ruins' => ['ruin', 'brass', 'aqueduct', 'machinery', 'abandoned', 'silent'],
    ];

    public function chooseTone(Game $game): string
    {
        $text = mb_strtolower($game->title.' '.$game->premise);

        $scores = collect(self::TONE_CUES)
            ->map(fn (array $cues): int => collect($cues)
                ->filter(fn (string $cue): bool => str_contains($text, $cue))
                ->count())
            ->filter();

        return $scores->isEmpty()
            ? 'sunlit'
            : (string) $scores->sortDesc()->keys()->first();
    }

    public function openingScene(Game $game): SceneDraft
    {
        return new SceneDraft(
            narration: self::BEATS[0],
            options: $this->options(),
            imagePrompt: 'A sunlit island jetty beneath a cliff stair, brass machinery turning above.',
        );
    }

    public function resolveRound(Game $game, Scene $scene): RoundResult
    {
        $next = $scene->number % count(self::BEATS);

        // Mirror the dice honestly, so a scripted run still exercises the code
        // paths that apply damage and hand out loot.
        $changes = $scene->actions
            ->map(function ($action) {
                $failed = $action->success === false;

                return new StateChange(
                    playerId: $action->player_id,
                    hpDelta: $failed ? -3 : 0,
                    addItems: $action->critical && $action->success ? ['a warm brass cog'] : [],
                    note: $failed ? 'Caught a glancing blow.' : '',
                );
            })
            ->reject(fn (StateChange $change) => $change->isNoop())
            ->values()
            ->all();

        $outcome = $scene->actions
            ->map(fn ($action) => $action->player->displayName().' '
                .($action->success === false ? 'fumbles it badly.' : 'manages it cleanly.'))
            ->implode(' ');

        return new RoundResult(
            resolution: $outcome !== '' ? $outcome : 'The party hesitates, and the moment passes.',
            storyBeat: "Scene {$scene->number} resolved.",
            stateChanges: $changes,
            nextScene: new SceneDraft(
                narration: self::BEATS[$next],
                options: $this->options(),
                imagePrompt: 'A lamplit interior of brass and water, deep inside an island.',
            ),
            gameOver: $scene->number >= 8,
            outcome: $scene->number >= 8 ? 'victory' : null,
        );
    }

    private function options(): array
    {
        return [
            ['key' => 'a', 'label' => 'Press forward carefully', 'skill' => 'wisdom', 'dc' => 12],
            ['key' => 'b', 'label' => 'Scout ahead alone', 'skill' => 'dexterity', 'dc' => 14],
            ['key' => 'c', 'label' => 'Call out a greeting', 'skill' => 'charisma', 'dc' => 11],
            ['key' => 'd', 'label' => 'Hold position and listen', 'skill' => null, 'dc' => null],
        ];
    }
}
