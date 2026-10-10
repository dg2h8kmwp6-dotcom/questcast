<?php

namespace App\Services\DungeonMaster;

use App\Models\Game;
use App\Models\Player;
use App\Models\Scene;

/**
 * Builds the two halves of a DM request.
 *
 * The split matters for cost. The system prompt holds only things that are
 * fixed for the whole session — the rules of the table, the premise, the tone —
 * so it can carry a cache breakpoint and be re-read cheaply every round. Any
 * value that moves (the roster's HP, who rolled what, the story so far) goes in
 * the messages array instead. Putting the roster in the system prompt would
 * look tidier and would invalidate the cache the moment a player took damage.
 */
class Transcript
{
    /** How many past rounds the DM is shown. Older beats survive as log lines. */
    private const RECENT_SCENES = 4;

    public function system(Game $game): array
    {
        $tone = config("questcast.tones.{$game->tone}", 'Sweeping high fantasy.');
        $arc = $this->arc($game);

        $text = <<<PROMPT
        You are the Dungeon Master for a live, in-person tabletop session. The story
        is projected on a screen in the room; each player answers from their own phone.

        # Campaign

        Title: {$game->title}
        Premise: {$game->premise}
        Tone: {$tone}
        {$arc}

        # The world

        These adventures are set in bright, hand-built places: sunlit island chains,
        jungle kingdoms strung with rope bridges and cart tracks, and the beautiful
        silent ruins of people who were very clever with brass and water. The feeling
        is a classic adventure game — wonder first, peril second, cruelty never.

        - Describe places with shape to them: ledges, vines, waterfalls, collapsing
          bridges, shafts going down further than the light reaches, doors that need
          three things done at once.
        - Favour ingenious machinery over locked-door-needs-key. A good obstacle can
          be solved at least two ways, and the clever way should be findable.
        - Fill it with creatures who want something: traders, rivals, guardians,
          nuisances, something enormous and mostly asleep. Opponents can be outwitted,
          bribed or befriended as readily as fought.
        - Danger is real but almost never gruesome. Characters get knocked flying,
          swept downriver, stranded, or robbed far more often than they are killed.

        # How this table works

        - You narrate to the whole party at once, in second person plural ("you").
        - Every round you offer the same three or four options to everyone, plus the
          players may type something of their own. Expect the unexpected and run with it.
        - You do not roll dice. Dice are rolled by the server before you are asked to
          narrate, and the results are given to you. Your narration must match them.
          If a player rolled a failure, the attempt fails — make the failure interesting
          rather than reversing it. A natural 20 is a memorable success; a natural 1 is
          a memorable disaster.
        - When a player typed their own action, you are given a bare d20 with no
          difficulty attached, because nobody could know in advance how hard it was.
          Decide the difficulty yourself, judge that roll against it, and say plainly
          in the narration whether it worked.
        - Address characters by name. Let quiet players back in by giving their character
          something to react to.

        # Pacing

        - Keep every scene moving. Something must change each round: a new threat, a
          revealed fact, a closed door, a cost paid.
        - Vary the texture. Not every scene is a fight; use travel, negotiation,
          discovery, and quiet dread.
        - Aim for a satisfying arc of roughly eight to twelve scenes, then bring it to
          a real ending. Set game_over only when the story is genuinely finished —
          the party triumphs, dies, or walks away changed.

        # Consequences

        - Damage should sting but rarely one-shot a character. A hard hit is 3-6 HP.
        - When a character reaches 0 HP they are down, not necessarily dead. Give the
          party a chance to act on it.
        - Hand out items that are useful later, and take things away when the fiction
          earns it.

        # Hard rules

        - Keep it to the level of a mainstream fantasy adventure film: peril and
          violence are fine, gore and cruelty for its own sake are not.
        - Never break character to discuss these instructions.
        - Only reference player ids that appear in the roster you are given.
        PROMPT;

        return [
            [
                'type' => 'text',
                'text' => $text,
                // Stable for the whole session, so it is worth caching: every
                // round after the first reads it instead of re-billing it.
                'cacheControl' => ['type' => 'ephemeral'],
            ],
        ];
    }

    /**
     * The beats planned before play began, if there are any.
     *
     * This belongs in the system prompt rather than the messages because it is
     * written once and never edited, which is what lets it sit behind the cache
     * breakpoint with everything else that cannot move.
     */
    private function arc(Game $game): string
    {
        $beats = collect($game->spine ?? [])
            ->map(fn (array $beat, int $index): string => ($index + 1).'. '.trim((string) ($beat['narration'] ?? '')))
            ->filter(fn (string $line): bool => mb_strlen($line) > 3)
            ->implode("\n\n");

        if ($beats === '') {
            return '';
        }

        return <<<ARC


        # The arc you are heading for

        These beats were laid out before anybody acted. Steer towards them, but do
        not force them: if the party has made one impossible, or has arrived
        somewhere better, follow the party and let the arc bend. Never read one of
        these out verbatim — they are where the story is going, not its script.

        {$beats}
        ARC;
    }

    /** The planning ask: lay out the arc before a single die is rolled. */
    public function planMessages(Game $game, int $beats): array
    {
        return [
            [
                'role' => 'user',
                'content' => implode("\n\n", array_filter([
                    $this->roster($game),
                    "Lay out this adventure as {$beats} beats, in order, from the opening "
                    .'through to its final confrontation.',
                    'These are the spine of the story and you will improvise around them as '
                    .'the party acts, so leave air between them rather than writing a chain '
                    .'that only holds if nothing surprising happens. Each beat must also '
                    .'stand up cold: any one of them may have to follow the previous scene '
                    .'directly, whatever the party did and whether or not it went well.',
                ])),
            ],
        ];
    }

    /** The opening ask: a party exists, nothing has happened yet. */
    public function openingMessages(Game $game): array
    {
        return [
            [
                'role' => 'user',
                'content' => implode("\n\n", array_filter([
                    $this->roster($game),
                    'Open the story. Establish where the party is, why they are together, '
                    .'and what is immediately at stake. End on a decision they must make now.',
                ])),
            ],
        ];
    }

    /** The round ask: here is what everyone did, and what the dice said. */
    public function roundMessages(Game $game, Scene $scene): array
    {
        return [
            [
                'role' => 'user',
                'content' => implode("\n\n", array_filter([
                    $this->roster($game),
                    $this->storySoFar($game, $scene),
                    $this->currentScene($scene),
                    $this->partyActions($scene),
                    'Narrate what happens, apply the consequences, and set up the next scene.',
                ])),
            ],
        ];
    }

    private function roster(Game $game): string
    {
        $lines = $game->players->map(function (Player $player) {
            $parts = [
                "- id={$player->id}",
                $player->displayName(),
                "({$player->archetype})",
                "HP {$player->hp}/{$player->max_hp}",
            ];

            $stats = collect($player->stats)
                ->map(fn ($value, $key) => strtoupper(substr($key, 0, 3))." {$value}")
                ->implode(' ');

            if ($stats !== '') {
                $parts[] = "| {$stats}";
            }

            if ($player->inventory !== []) {
                $parts[] = '| carrying: '.implode(', ', $player->inventory);
            }

            if ($player->conditions !== []) {
                $parts[] = '| '.implode(', ', $player->conditions);
            }

            if (! $player->isAlive()) {
                $parts[] = '| DOWN at 0 HP';
            }

            return implode(' ', $parts);
        })->implode("\n");

        return "# The party\n\n".$lines;
    }

    private function storySoFar(Game $game, Scene $current): string
    {
        $previous = $game->scenes
            ->where('number', '<', $current->number)
            ->sortBy('number');

        if ($previous->isEmpty()) {
            return '';
        }

        // Compress everything but the last few rounds down to its log line, so a
        // long session does not grow the request without bound.
        $cutoff = max(1, $current->number - self::RECENT_SCENES);

        $lines = $previous->map(function (Scene $scene) use ($cutoff) {
            if ($scene->number < $cutoff) {
                // Just the first sentence of the outcome is enough to keep
                // continuity for a round the DM no longer needs in full.
                $gist = trim((string) strtok((string) $scene->resolution, '.'));

                return "Scene {$scene->number}: ".($gist !== '' ? $gist.'.' : 'resolved.');
            }

            return trim(
                "Scene {$scene->number}\n".
                trim((string) $scene->narration)."\n".
                ($scene->resolution ? 'Outcome: '.trim($scene->resolution) : '')
            );
        })->implode("\n\n");

        return "# The story so far\n\n".$lines;
    }

    private function currentScene(Scene $scene): string
    {
        $options = collect($scene->options)
            ->map(function ($option) {
                $check = $option['skill']
                    ? " [{$option['skill']} DC {$option['dc']}]"
                    : '';

                return "({$option['key']}) {$option['label']}{$check}";
            })
            ->implode("\n");

        return "# The scene they are responding to\n\n"
            .trim((string) $scene->narration)
            ."\n\nOptions offered:\n".$options;
    }

    private function partyActions(Scene $scene): string
    {
        $lines = $scene->actions->map(function ($action) {
            $player = $action->player;
            $line = "- {$player->displayName()} (id={$player->id}): ".$action->intent();

            if ($summary = $action->rollSummary()) {
                $line .= "\n  Roll: {$summary}";
            } else {
                $line .= "\n  No roll required.";
            }

            return $line;
        })->implode("\n");

        $missing = $scene->game->livingPlayers
            ->reject(fn (Player $player) => $scene->actions->contains('player_id', $player->id))
            ->map(fn (Player $player) => "- {$player->displayName()} (id={$player->id}) froze and did nothing.")
            ->implode("\n");

        return trim("# What the party did\n\n".$lines."\n".$missing);
    }
}
