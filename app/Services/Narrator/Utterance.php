<?php

namespace App\Services\Narrator;

/** A rendered passage of speech, still in memory. */
class Utterance
{
    public function __construct(
        public string $audio,
        public string $extension = 'mp3',
        public string $contentType = 'audio/mpeg',
    ) {}
}
