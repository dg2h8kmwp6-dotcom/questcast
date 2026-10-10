<?php

namespace App\Services\Narrator\Contracts;

use App\Services\Narrator\Utterance;

interface Narrator
{
    /**
     * Render a passage of story as speech.
     *
     * Returning null means "no voice this time" rather than "something broke":
     * a missing key, a disabled narrator or a provider having a bad afternoon
     * all land here, and the room simply reads the screen instead.
     */
    public function speak(string $passage): ?Utterance;
}
