<?php

namespace App\Services\DungeonMaster;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Exceptions\DungeonMasterFailed;
use App\Models\Game;
use App\Models\Scene;
use App\Services\DungeonMaster\Contracts\DungeonMaster;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

class ClaudeDungeonMaster implements DungeonMaster
{
    public function __construct(
        private readonly Client $client,
        private readonly Transcript $transcript,
    ) {}

    /**
     * A single cheap call, on the small model, before anything else happens.
     *
     * This runs once per game and never again, so it is deliberately kept off
     * the storyteller's own model and out of the cached system prompt.
     */
    public function chooseTone(Game $game): string
    {
        $tones = config('questcast.tones');

        $catalogue = collect($tones)
            ->map(fn (string $description, string $key): string => "{$key} — {$description}")
            ->implode("\n");

        $system = <<<PROMPT
        You choose the register a tabletop adventure will be run in.

        Read the title and premise and pick the single tone below that best fits
        what this room is about to play. Judge it on the feel of the place and
        the kind of trouble in it, not on isolated words.

        {$catalogue}
        PROMPT;

        try {
            $message = $this->client->messages->create(
                model: config('questcast.dm.classifier_model'),
                maxTokens: 256,
                system: $system,
                messages: [[
                    'role' => 'user',
                    'content' => "Title: {$game->title}\n\nPremise: {$game->premise}",
                ]],
                outputConfig: [
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => Schema::tone(array_keys($tones)),
                    ],
                ],
            );

            return (string) ($this->decode($message)['tone'] ?? '');
        } catch (Throwable $e) {
            // A mood is a convenience. Losing it costs the host a default, not
            // a game, so this never propagates.
            Log::warning('Could not infer a tone from the premise.', [
                'game_id' => $game->id,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    public function planAdventure(Game $game, int $beats): array
    {
        $payload = $this->ask(
            $game,
            $this->transcript->planMessages($game, $beats),
            Schema::spine($beats),
        );

        // The count was asked for in words rather than in the schema, so trim
        // here if the storyteller was generous. Coming back short is fine — it
        // only means a shorter safety net.
        $drafts = collect($payload['beats'] ?? [])
            ->map(fn (array $beat): SceneDraft => SceneDraft::fromArray($beat))
            ->reject(fn (SceneDraft $draft): bool => $draft->isEmpty())
            ->take($beats)
            ->values()
            ->all();

        if ($drafts === []) {
            throw DungeonMasterFailed::malformed('the adventure came back with no beats.');
        }

        return $drafts;
    }

    public function openingScene(Game $game): SceneDraft
    {
        $payload = $this->ask(
            $game,
            $this->transcript->openingMessages($game),
            Schema::scene(),
        );

        $draft = SceneDraft::fromArray($payload);

        if ($draft->isEmpty()) {
            throw DungeonMasterFailed::malformed('the opening scene came back with no narration.');
        }

        return $draft;
    }

    public function resolveRound(Game $game, Scene $scene): RoundResult
    {
        $payload = $this->ask(
            $game,
            $this->transcript->roundMessages($game, $scene),
            Schema::round(),
        );

        return RoundResult::fromArray($payload);
    }

    /**
     * One request, one decoded JSON object.
     *
     * Thinking is left unset: on Claude Opus 5 that runs adaptive thinking,
     * which is what we want here — the DM has to hold continuity, dice results
     * and party state together before it writes.
     */
    private function ask(Game $game, array $messages, array $schema): array
    {
        try {
            $message = $this->client->messages->create(
                model: config('questcast.dm.model'),
                maxTokens: config('questcast.dm.max_tokens'),
                system: $this->transcript->system($game),
                messages: $messages,
                outputConfig: [
                    'effort' => config('questcast.dm.effort'),
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => $schema,
                    ],
                ],
            );
        } catch (APIStatusException $e) {
            // The type alone says "invalid_request_error" and nothing about
            // which part of the request was invalid, which is useless from a
            // log. Keep both.
            $detail = trim(implode(' — ', array_filter([
                $e->type?->value,
                mb_substr($e->getMessage(), 0, 300),
            ])));

            Log::warning('The storyteller rejected a request.', [
                'game_id' => $game->id,
                'detail' => $e->getMessage(),
            ]);

            throw DungeonMasterFailed::unreachable($detail);
        } catch (Throwable $e) {
            throw DungeonMasterFailed::unreachable($e->getMessage());
        }

        // A safety classifier can decline a request without raising: HTTP 200
        // with a refusal stop reason and an empty-ish body. Check before reading.
        if ($message->stopReason === 'refusal') {
            Log::warning('Dungeon master refused a scene.', [
                'game_id' => $game->id,
                'category' => $message->stopDetails?->category,
            ]);

            throw DungeonMasterFailed::refused(
                $message->stopDetails?->category,
                $message->stopDetails?->explanation,
            );
        }

        if ($message->stopReason === 'max_tokens') {
            throw DungeonMasterFailed::malformed('the reply was cut off before it finished.');
        }

        $this->recordUsage($game, $message);

        return $this->decode($message);
    }

    private function decode(object $message): array
    {
        // Thinking blocks can precede the text block, so never index content[0].
        $json = null;

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json = $block->text;
                break;
            }
        }

        if ($json === null) {
            throw DungeonMasterFailed::malformed('the reply contained no text block.');
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw DungeonMasterFailed::malformed($e->getMessage());
        }

        if (! is_array($decoded)) {
            throw DungeonMasterFailed::malformed('the reply was not a JSON object.');
        }

        return $decoded;
    }

    /**
     * Cache hits are the difference between a cheap session and an expensive
     * one, and a silent invalidation is invisible without this line.
     */
    private function recordUsage(Game $game, object $message): void
    {
        Log::info('Dungeon master usage.', [
            'game_id' => $game->id,
            'input' => $message->usage->inputTokens ?? null,
            'output' => $message->usage->outputTokens ?? null,
            'cache_write' => $message->usage->cacheCreationInputTokens ?? null,
            'cache_read' => $message->usage->cacheReadInputTokens ?? null,
        ]);
    }
}
