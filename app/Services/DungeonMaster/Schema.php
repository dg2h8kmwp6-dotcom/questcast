<?php

namespace App\Services\DungeonMaster;

/**
 * JSON schemas the DM's replies are constrained to.
 *
 * Two deliberate choices keep these strict rather than convenient: every
 * property is listed in "required" and "additionalProperties" is always false,
 * which is what structured outputs needs to guarantee a parseable reply. Where
 * a value is logically optional we use a sentinel ("none", 0) instead of a
 * nullable type, so there are no union types to defend against downstream.
 */
class Schema
{
    public const SKILLS = [
        'strength', 'dexterity', 'constitution',
        'intelligence', 'wisdom', 'charisma', 'none',
    ];

    public static function scene(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'narration' => [
                    'type' => 'string',
                    'description' => 'Read aloud to the room. 60-120 words, second person plural, present tense.',
                ],
                'image_prompt' => [
                    'type' => 'string',
                    'description' => 'One sentence describing the scene visually, for future illustration.',
                ],
                'options' => self::options(),
            ],
            'required' => ['narration', 'image_prompt', 'options'],
            'additionalProperties' => false,
        ];
    }

    public static function round(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'resolution' => [
                    'type' => 'string',
                    'description' => 'What happened as a result of the party\'s actions. 80-160 words. Must be consistent with every dice result given.',
                ],
                'story_beat' => [
                    'type' => 'string',
                    'description' => 'One short line summarising this round for the campaign log.',
                ],
                'state_changes' => [
                    'type' => 'array',
                    'description' => 'Mechanical consequences. Omit players who were unaffected.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'player_id' => ['type' => 'integer', 'description' => 'Must be an id from the party roster.'],
                            'hp_delta' => ['type' => 'integer', 'description' => 'Negative for damage, positive for healing.'],
                            'add_items' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'remove_items' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'conditions' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'The player\'s complete condition list after this round, e.g. ["poisoned"]. An empty array clears them.',
                            ],
                            'note' => ['type' => 'string', 'description' => 'Short reason, shown on the player\'s phone.'],
                        ],
                        'required' => ['player_id', 'hp_delta', 'add_items', 'remove_items', 'conditions', 'note'],
                        'additionalProperties' => false,
                    ],
                ],
                'game_over' => [
                    'type' => 'boolean',
                    'description' => 'True only when the story is genuinely finished.',
                ],
                'outcome' => [
                    'type' => 'string',
                    'enum' => ['ongoing', 'victory', 'defeat'],
                ],
                'next_scene' => [
                    'type' => 'object',
                    'description' => 'The scene that follows. When game_over is true, set narration to "" and options to [].',
                    'properties' => [
                        'narration' => ['type' => 'string'],
                        'image_prompt' => ['type' => 'string'],
                        'options' => self::options(),
                    ],
                    'required' => ['narration', 'image_prompt', 'options'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['resolution', 'story_beat', 'state_changes', 'game_over', 'outcome', 'next_scene'],
            'additionalProperties' => false,
        ];
    }

    private static function options(): array
    {
        return [
            'type' => 'array',
            'description' => 'Three or four distinct courses of action offered to every player.',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string', 'description' => 'Lowercase letter: a, b, c, d.'],
                    'label' => ['type' => 'string', 'description' => 'What the player would do, 3-12 words, starting with a verb.'],
                    'skill' => [
                        'type' => 'string',
                        'enum' => self::SKILLS,
                        'description' => 'Ability this option tests, or "none" for a choice that cannot fail.',
                    ],
                    'dc' => [
                        'type' => 'integer',
                        'description' => 'Difficulty 8 (easy) to 18 (very hard). Use 0 when skill is "none".',
                    ],
                ],
                'required' => ['key', 'label', 'skill', 'dc'],
                'additionalProperties' => false,
            ],
        ];
    }
}
