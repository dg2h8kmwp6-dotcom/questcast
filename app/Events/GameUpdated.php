<?php

namespace App\Events;

use App\Models\Game;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The single "something changed, re-read it" signal.
 *
 * Deliberately thin: it carries no game state, only enough for a client to
 * decide whether it cares. Every screen re-reads from the database when this
 * lands, so there is exactly one source of truth and no way for a dropped or
 * reordered websocket frame to leave a phone showing a stale scene.
 */
class GameUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Scenes are written inside a transaction, and clients re-read the database
     * the moment this lands. Holding the broadcast until the commit stops a
     * worker from telling the room to re-read rows that are not visible yet.
     */
    public bool $afterCommit = true;

    public function __construct(
        public Game $game,
        public string $reason = 'state',
    ) {}

    /** Public channel: knowing the code is already enough to join the table. */
    public function broadcastOn(): array
    {
        return [new Channel('game.'.$this->game->code)];
    }

    public function broadcastAs(): string
    {
        return 'updated';
    }

    public function broadcastWith(): array
    {
        return [
            'reason' => $this->reason,
            'status' => $this->game->status->value,
            'scene' => $this->game->scene_number,
        ];
    }
}
