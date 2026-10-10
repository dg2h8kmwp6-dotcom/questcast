<?php

namespace App\Enums;

enum GameStatus: string
{
    /** Players are joining from their phones; no scene exists yet. */
    case Lobby = 'lobby';

    /** The DM is composing a scene. Phones show a waiting state. */
    case Generating = 'generating';

    /** A scene is open and the party is deciding. */
    case AwaitingActions = 'awaiting_actions';

    /** Every action is in and the DM is resolving the round. */
    case Resolving = 'resolving';

    /** The story is finished (victory, defeat, or the host called it). */
    case Ended = 'ended';

    public function isPlayable(): bool
    {
        return $this !== self::Ended;
    }

    public function acceptsNewPlayers(): bool
    {
        return $this === self::Lobby;
    }

    public function label(): string
    {
        return match ($this) {
            self::Lobby => 'Gathering the party',
            self::Generating => 'The DM is thinking',
            self::AwaitingActions => 'Awaiting the party',
            self::Resolving => 'Resolving the round',
            self::Ended => 'The tale is told',
        };
    }
}
