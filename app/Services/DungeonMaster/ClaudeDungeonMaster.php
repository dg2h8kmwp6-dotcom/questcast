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
            throw DungeonMasterFailed::unreachable($e->type?->value ?? $e->getMessage());
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
