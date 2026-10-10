<?php

namespace App\Enums;

enum SceneStatus: string
{
    case Generating = 'generating';
    case Open = 'open';
    case Resolving = 'resolving';
    case Resolved = 'resolved';
}
