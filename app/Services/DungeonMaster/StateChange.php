<?php

namespace App\Services\DungeonMaster;

/** One player's mechanical consequences from a round. */
readonly class StateChange
{
    /**
     * @param  array<int,string>  $addItems
     * @param  array<int,string>  $removeItems
     * @param  array<int,string>  $conditions
     */
    public function __construct(
        public int $playerId,
        public int $hpDelta = 0,
        public array $addItems = [],
        public array $removeItems = [],
        public array $conditions = [],
        public string $note = '',
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            playerId: (int) ($payload['player_id'] ?? 0),
            hpDelta: (int) ($payload['hp_delta'] ?? 0),
            addItems: array_values(array_filter((array) ($payload['add_items'] ?? []))),
            removeItems: array_values(array_filter((array) ($payload['remove_items'] ?? []))),
            conditions: array_values(array_filter((array) ($payload['conditions'] ?? []))),
            note: trim((string) ($payload['note'] ?? '')),
        );
    }

    public function isNoop(): bool
    {
        return $this->hpDelta === 0
            && $this->addItems === []
            && $this->removeItems === []
            && $this->conditions === [];
    }
}
