<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dungeon Master
    |--------------------------------------------------------------------------
    |
    | "driver" picks who narrates. "claude" calls the Claude API; "scripted" is
    | a deterministic stand-in that needs no API key, which keeps local play and
    | the test suite free and offline.
    |
    */

    'dm' => [
        'driver' => env('QUESTCAST_DM_DRIVER', 'claude'),

        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),

        // Reading a premise and naming its register is not a job for the
        // storyteller's model, and it happens once per game.
        'classifier_model' => env('ANTHROPIC_CLASSIFIER_MODEL', 'claude-haiku-4-5-20251001'),

        // Narration is creative but not a hard reasoning problem, and the room
        // is waiting on it, so we trade a little depth for latency. Raise to
        // "high" if scenes feel shallow.
        'effort' => env('ANTHROPIC_EFFORT', 'medium'),

        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 16000),

        // How long a generation job may run before the host screen offers a retry.
        'stuck_after_seconds' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | The storyteller's voice
    |--------------------------------------------------------------------------
    |
    | "driver" picks who reads the story aloud on the host screen. "elevenlabs"
    | calls the API; "silent" is the no-op used by the test suite and by any
    | install without a key, so a fresh clone is still fully playable.
    |
    | Speech is rendered on the queue and cached on the disk below, keyed by the
    | text itself — a passage is paid for once and replayed for free. Point the
    | disk at object storage in production, because app instances have an
    | ephemeral filesystem and anything written beside the code is gone on the
    | next deploy.
    |
    | Run `php artisan narrator:voices` to list the voices on your account and
    | find one that sounds like the storyteller you want.
    |
    */

    'narrator' => [
        'driver' => env('QUESTCAST_NARRATOR_DRIVER', 'elevenlabs'),

        'voice' => env('ELEVENLABS_VOICE_ID', ''),

        // Quality over latency: nobody is waiting on this, because it is
        // rendered off the queue while the scene is already on screen.
        'model' => env('ELEVENLABS_MODEL', 'eleven_multilingual_v2'),

        'format' => env('ELEVENLABS_FORMAT', 'mp3_44100_128'),

        'timeout' => (int) env('ELEVENLABS_TIMEOUT', 60),

        'disk' => env('NARRATION_DISK', 'public'),

        /*
         | A storyteller, not a newsreader, reading 120 words at a time to a
         | room that is half-listening.
         |
         | Stability trades consistency against emotional range: too high and
         | every scene lands the same way, too low and a long passage wanders,
         | which also costs you invented names. Style adds theatrics and some
         | latency, which this does not have to care about. Speed below 1.0
         | buys the room a beat to picture what it just heard.
         |
         | These are reasonable starting points, not answers. Pick a voice
         | first, then tune these by ear.
         */
        'voice_settings' => [
            'stability' => (float) env('ELEVENLABS_STABILITY', 0.45),
            'similarity_boost' => (float) env('ELEVENLABS_SIMILARITY', 0.75),
            'style' => (float) env('ELEVENLABS_STYLE', 0.45),
            'speed' => (float) env('ELEVENLABS_SPEED', 0.92),
            'use_speaker_boost' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Player portraits
    |--------------------------------------------------------------------------
    |
    | Selfies need a disk that can hand back a public URL. Locally that is the
    | "public" disk; on Laravel Cloud point this at an object storage bucket,
    | because app instances have an ephemeral filesystem and anything written
    | beside the code disappears on the next deploy.
    |
    */

    'portraits' => [
        'disk' => env('PORTRAIT_DISK', 'public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Table defaults
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'max_players' => 8,
        'allow_free_text' => true,
        'starting_hp' => 12,
    ],

    'tones' => [
        'sunlit' => 'A bright day on the road. Friendly creatures, bouncy peril, nobody stays sad for long.',
        'canopy' => 'Deep forest kingdom. Rope bridges, cart tracks, drums, and a hoard worth winning back.',
        'ruins' => 'Beautiful empty machinery. Everyone has gone; every answer is a puzzle they left behind.',
        'tempest' => 'Storm-lashed highlands and flooded halls. Hard weather, real pressure, things that glow.',
        'frostpeak' => 'A long climb. Snowbound summits, ice caverns, and a warm hearth somewhere above.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Shape of an adventure
    |--------------------------------------------------------------------------
    |
    | Part of the story is laid out before anybody acts. Those beats are handed
    | to the storyteller as the arc it is improvising towards, and they are also
    | what the game falls forward onto when a round fails to resolve — which is
    | the difference between a wobble nobody notices and a host reloading the
    | screen in front of the room.
    |
    | "spine" is how many of "scenes" are planned up front. Half is a deliberate
    | balance: enough to survive a bad run of API calls, not so much that the
    | adventure stops responding to what the party actually does.
    |
    */

    'story' => [
        'scenes' => (int) env('QUESTCAST_STORY_SCENES', 10),
        'spine' => (int) env('QUESTCAST_STORY_SPINE', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Adventures off the shelf
    |--------------------------------------------------------------------------
    |
    | Staring at an empty premise box is the slowest part of starting a game, so
    | the home page offers these and lets you edit whatever you pick. Each one
    | names its own tone; leaving that null sends it through the same inference
    | a hand-written premise gets.
    |
    */

    'adventures' => [
        [
            'title' => 'The Clockwork Valley',
            'premise' => 'Every map says the valley is empty. The brass birds say otherwise — they circle '
                .'the old quarry at the same hour each day and never once land. Your cart is unhitched at '
                .'the treeline, and the light is going.',
            'tone' => 'sunlit',
        ],
        [
            'title' => 'The Buried Gate',
            'premise' => 'The quarry crew hit a door forty feet down and walked off the site the same '
                .'afternoon. The pit floods when it rains, the sky has not cleared in a week, and the '
                .'foreman is offering a month of wages to whoever goes down first.',
            'tone' => 'tempest',
        ],
        [
            'title' => 'The Lantern Road',
            'premise' => 'Somebody has been lighting the lamps along the old cart road through the canopy, '
                .'one every evening, climbing towards a kingdom abandoned sixty years ago. Tonight the whole '
                .'road is lit, which means whoever is doing it has reached the top.',
            'tone' => 'canopy',
        ],
        [
            'title' => 'The Patient Machine',
            'premise' => 'The valley is strung with brass aqueducts that still run, still turn, still pour, '
                .'and nobody has lived here in four hundred years. At the centre of it something enormous is '
                .'being watered very carefully. The farms downstream would like it to stop.',
            'tone' => 'ruins',
        ],
        [
            'title' => 'The Ninth Terrace',
            'premise' => 'Eight terraces climb the mountain, each with a hearth kept burning by whoever passed '
                .'through last. The ninth has not been reached in living memory. This week its chimney started '
                .'smoking, and the snow above it is melting in a straight line.',
            'tone' => 'frostpeak',
        ],
        [
            'title' => 'The Banner Run',
            'premise' => 'Six teams, nine waystations, and a prize nobody alive has actually seen. The run '
                .'has crossed these hills every summer for two hundred years without incident, until this '
                .'morning, when somebody started moving the trail markers — and the markers are what keep '
                .'runners off the scree.',
            'tone' => 'sunlit',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Who you can be
    |--------------------------------------------------------------------------
    |
    | Six readable silhouettes rather than a character sheet. The stats are
    | ordinary ability scores, so a +3 means what it has always meant.
    |
    */

    'archetypes' => [
        'Brawler' => ['strength' => 16, 'dexterity' => 12, 'constitution' => 15, 'intelligence' => 9, 'wisdom' => 11, 'charisma' => 10],
        'Scout' => ['strength' => 10, 'dexterity' => 17, 'constitution' => 12, 'intelligence' => 13, 'wisdom' => 11, 'charisma' => 14],
        'Runewright' => ['strength' => 8, 'dexterity' => 12, 'constitution' => 11, 'intelligence' => 17, 'wisdom' => 14, 'charisma' => 10],
        'Keeper' => ['strength' => 12, 'dexterity' => 10, 'constitution' => 14, 'intelligence' => 11, 'wisdom' => 17, 'charisma' => 13],
        'Tracker' => ['strength' => 13, 'dexterity' => 16, 'constitution' => 13, 'intelligence' => 11, 'wisdom' => 14, 'charisma' => 9],
        'Songsmith' => ['strength' => 9, 'dexterity' => 14, 'constitution' => 11, 'intelligence' => 13, 'wisdom' => 11, 'charisma' => 17],
    ],

];
