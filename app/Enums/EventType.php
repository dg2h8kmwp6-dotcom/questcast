<?php

namespace App\Enums;

/** Entry kinds in the append-only game log the host screen renders. */
enum EventType: string
{
    case Narration = 'narration';
    case Resolution = 'resolution';
    case Roll = 'roll';
    case System = 'system';
    case Join = 'join';
    case Damage = 'damage';
    case Loot = 'loot';
    case Death = 'death';
}
