<?php

namespace App\Services\DungeonMaster;

/** A scene as the DM wrote it, before it is persisted. */
readonly class SceneDraft
{
    /**
     * @param  array<int,array{key:string,label:string,skill:?string,dc:?int}>  $options
     * @param  ?string  $bridge  On a planned beat, the line that carries the party
     *                           here from whatever just happened. Used as the
     *                           resolution when a round has to fall forward.
     */
    public function __construct(
        public string $narration,
        public array $options,
        public ?string $imagePrompt = null,
        public ?string $bridge = null,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            narration: trim((string) ($payload['narration'] ?? '')),
            options: self::normalizeOptions($payload['options'] ?? []),
            imagePrompt: ($payload['image_prompt'] ?? null) ?: null,
            bridge: trim((string) ($payload['bridge'] ?? '')) ?: null,
        );
    }

    /** @return array{narration:string,options:array,image_prompt:?string,bridge:?string} */
    public function toArray(): array
    {
        return [
            'narration' => $this->narration,
            'options' => $this->options,
            'image_prompt' => $this->imagePrompt,
            'bridge' => $this->bridge,
        ];
    }

    /**
     * The schema asks for "none" and 0 rather than nulls, which keeps the JSON
     * schema strict and unambiguous. Translate those sentinels back here.
     */
    private static function normalizeOptions(array $options): array
    {
        return collect($options)
            ->filter(fn ($option) => filled($option['label'] ?? null))
            ->values()
            ->map(function ($option, $index) {
                $skill = strtolower((string) ($option['skill'] ?? 'none'));
                $dc = (int) ($option['dc'] ?? 0);

                return [
                    'key' => (string) ($option['key'] ?? chr(97 + $index)),
                    'label' => trim((string) $option['label']),
                    'skill' => $skill === 'none' ? null : $skill,
                    'dc' => $dc > 0 ? $dc : null,
                ];
            })
            ->all();
    }

    public function isEmpty(): bool
    {
        return $this->narration === '';
    }
}
