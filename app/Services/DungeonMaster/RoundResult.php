<?php

namespace App\Services\DungeonMaster;

readonly class RoundResult
{
    /** @param array<int,StateChange> $stateChanges */
    public function __construct(
        public string $resolution,
        public string $storyBeat,
        public array $stateChanges,
        public ?SceneDraft $nextScene,
        public bool $gameOver,
        public ?string $outcome,
    ) {}

    public static function fromArray(array $payload): self
    {
        $gameOver = (bool) ($payload['game_over'] ?? false);
        $next = SceneDraft::fromArray($payload['next_scene'] ?? []);

        return new self(
            resolution: trim((string) ($payload['resolution'] ?? '')),
            storyBeat: trim((string) ($payload['story_beat'] ?? '')),
            stateChanges: collect($payload['state_changes'] ?? [])
                ->map(fn ($change) => StateChange::fromArray($change))
                ->reject(fn (StateChange $change) => $change->playerId === 0)
                ->values()
                ->all(),
            // A finished story has no next scene even if the model supplied one.
            nextScene: ($gameOver || $next->isEmpty()) ? null : $next,
            gameOver: $gameOver,
            outcome: ($payload['outcome'] ?? 'ongoing') === 'ongoing'
                ? null
                : (string) $payload['outcome'],
        );
    }
}
