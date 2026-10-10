<?php

namespace App\Livewire\Host;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Services\GameEngine;
use App\Support\Qr;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The main screen — the one plugged into the TV.
 *
 * It owns the host controls and is the only surface that may start, force, or
 * end a round. Everything it renders is re-read from the database whenever a
 * GameUpdated broadcast lands.
 */
#[Layout('components.layouts.app')]
class Screen extends Component
{
    public Game $game;

    public function mount(Game $game): void
    {
        // The host secret lives in this browser's session; without it this URL
        // is just a spectator view, so we refuse rather than silently degrade.
        abort_unless(
            session("host_token.{$game->code}") === $game->host_token,
            403,
            'This screen belongs to another host. Start your own game from the home page.',
        );

        $this->game = $game;
    }

    #[Computed]
    public function players()
    {
        return $this->game->players()->orderBy('id')->get();
    }

    #[Computed]
    public function scene()
    {
        return $this->game->currentScene;
    }

    #[Computed]
    public function decided()
    {
        return $this->scene
            ? $this->scene->actions()->pluck('player_id')->all()
            : [];
    }

    /**
     * The log the host screen renders, oldest first.
     *
     * A single round can log a narration, a roll per player, damage, loot and
     * a death, so the window has to hold several rounds for the scrollback to
     * be worth anything.
     */
    #[Computed]
    public function log()
    {
        return $this->game->events()->latest('id')->limit(40)->get()->reverse();
    }

    /**
     * What the storyteller still has to read aloud, oldest first.
     *
     * The last two scenes are offered rather than just the current one, because
     * a round produces two passages — what came of the party's actions, and
     * where that leaves them — and the room wants both, in that order. The
     * screen hands over everything it has and lets the browser skip whatever it
     * has already spoken, which is what keeps the slow poll from reading the
     * same scene over and over.
     *
     * @return array<int, array{key: string, url: string}>
     */
    #[Computed]
    public function voiceOver(): array
    {
        $passages = [];

        // reorder() rather than orderByDesc(): the relation sorts ascending
        // already, and a second clause would simply be ignored.
        $recent = $this->game->scenes()
            ->reorder('number', 'desc')
            ->limit(2)
            ->get()
            ->reverse();

        foreach ($recent as $scene) {
            foreach (['narration', 'resolution'] as $passage) {
                $url = $passage === 'narration'
                    ? $scene->narrationAudioUrl()
                    : $scene->resolutionAudioUrl();

                if ($url) {
                    $passages[] = ['key' => "{$scene->id}-{$passage}", 'url' => $url];
                }
            }
        }

        return $passages;
    }

    /**
     * Where the adventurer stands on the trail.
     *
     * The road always runs a little past where the party has got to, so there
     * is visibly somewhere left to go; once a story runs long the trail stops
     * growing and he simply walks the last stretch.
     */
    #[Computed]
    public function journey(): array
    {
        $scene = (int) $this->game->scene_number;
        $total = max(10, min(14, $scene + 2));
        $current = max(1, min($scene, $total));

        return [
            'total' => $total,
            'current' => $current,
            'started' => $scene > 0,
            'percent' => $total > 1 ? ($current - 1) / ($total - 1) * 100 : 0,
        ];
    }

    #[Computed]
    public function joinUrl(): string
    {
        return route('play', $this->game->code);
    }

    #[Computed]
    public function qr(): string
    {
        return Qr::svg($this->joinUrl, 300);
    }

    /**
     * Re-read state when anything in this game changes.
     *
     * The broadcast payload is only used to pick a sound; everything drawn on
     * screen is re-read from the database, so a missed frame costs a cue and
     * never the board itself.
     */
    #[On('echo:game.{game.code},updated')]
    public function refreshBoard(?array $payload = null): void
    {
        $this->game->refresh();

        unset($this->players, $this->scene, $this->decided, $this->log, $this->journey, $this->voiceOver);

        $cue = match ($payload['reason'] ?? null) {
            'player-joined' => 'join',
            'scene-opened' => 'scene',
            'generating', 'resolving' => 'thinking',
            'action-submitted' => 'tap',
            'ended' => 'fanfare',
            'error' => 'fail',
            default => null,
        };

        if ($cue) {
            $this->dispatch('sfx', name: $cue);
        }
    }

    public function begin(GameEngine $engine): void
    {
        $this->guardLive();

        try {
            $engine->begin($this->game);
        } catch (\RuntimeException $e) {
            $this->addError('control', $e->getMessage());
        }

        $this->refreshBoard();
    }

    /** Resolve now, without waiting on a player who has wandered off. */
    public function forceResolve(GameEngine $engine): void
    {
        $this->guardLive();

        $engine->closeScene($this->game);
        $this->refreshBoard();
    }

    public function retry(GameEngine $engine): void
    {
        $this->guardLive();

        $this->game->update(['last_error' => null]);

        $this->game->status === GameStatus::Lobby
            ? $engine->begin($this->game)
            : $engine->closeScene($this->game);

        $this->refreshBoard();
    }

    public function endGame(GameEngine $engine): void
    {
        $this->guardLive();

        $engine->end($this->game);
        $this->refreshBoard();
    }

    private function guardLive(): void
    {
        abort_unless(
            session("host_token.{$this->game->code}") === $this->game->host_token,
            403,
        );
    }

    public function render()
    {
        return view('livewire.host.screen');
    }
}
