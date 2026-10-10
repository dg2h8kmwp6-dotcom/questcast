<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The DM could not produce a usable scene.
 *
 * Carries a message that is safe to show the host screen, because the host is
 * the person who has to decide whether to retry or end the session.
 */
class DungeonMasterFailed extends RuntimeException
{
    public static function refused(?string $category, ?string $explanation): self
    {
        return new self(sprintf(
            'The storyteller declined to continue this thread (%s). Try steering the story somewhere else.%s',
            $category ?: 'unspecified',
            $explanation ? ' Detail: '.$explanation : '',
        ));
    }

    public static function malformed(string $detail): self
    {
        return new self('The storyteller returned something unreadable: '.$detail);
    }

    public static function unreachable(string $detail): self
    {
        return new self('Could not reach the storyteller: '.$detail);
    }
}
