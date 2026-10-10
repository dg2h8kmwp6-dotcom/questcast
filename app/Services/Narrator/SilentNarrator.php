<?php

namespace App\Services\Narrator;

use App\Services\Narrator\Contracts\Narrator;

/**
 * The narrator who says nothing.
 *
 * Used by the test suite and by any install without an ElevenLabs key, so the
 * game is fully playable — and fully testable — without spending a character.
 */
class SilentNarrator implements Narrator
{
    public function speak(string $passage): ?Utterance
    {
        return null;
    }
}
