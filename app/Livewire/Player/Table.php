<?php

namespace App\Livewire\Player;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\Player;
use App\Services\GameEngine;
use App\Support\Portrait;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use RuntimeException;

/**
 * What a player holds in their hand.
 *
 * Identity is a random token in the phone's session — no accounts, no
 * passwords. Reopening the same browser returns you to your character;
 * a different browser is a different player.
 */
#[Layout('components.layouts.phone')]
class Table extends Component
{
    use WithFileUploads;

    public Game $game;

    public ?int $playerId = null;

    // Join form
    public string $name = '';

    public string $archetype = 'Brawler';

    private const PHOTO_RULES = ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:8192'];

    /** The selfie, straight off the phone's camera. Always optional. */
    public $photo = null;

    // Action form
    public string $freeText = '';

    public function mount(Game $game): void
    {
        $this->game = $game;

        if ($token = session("player_token.{$game->code}")) {
            $this->playerId = $game->players()->where('token', $token)->value('id');
        }
    }

    #[Computed]
    public function player(): ?Player
    {
        return $this->playerId
            ? $this->game->players()->find($this->playerId)
            : null;
    }

    #[Computed]
    public function scene()
    {
        return $this->game->currentScene;
    }

    #[Computed]
    public function action()
    {
        return ($this->player && $this->scene)
            ? $this->player->actionFor($this->scene)
            : null;
    }

    #[Computed]
    public function waitingOn(): int
    {
        if (! $this->scene) {
            return 0;
        }

        return max(0, $this->game->livingPlayers()->count()
            - $this->scene->actions()->count());
    }

    #[On('echo:game.{game.code},updated')]
    public function refreshTable(): void
    {
        $this->game->refresh();

        unset($this->player, $this->scene, $this->action, $this->waitingOn);
    }

    public function updatedPhoto(): void
    {
        $this->validate(['photo' => self::PHOTO_RULES]);

        $this->dispatch('sfx', name: 'loot');
    }

    public function removePhoto(): void
    {
        $this->photo = null;
        $this->resetErrorBag('photo');
    }

    public function join(GameEngine $engine): void
    {
        $this->validate([
            'name' => [
                'required', 'string', 'min:2', 'max:24',
                // Names are shouted across a room; two Bens would be chaos.
                Rule::unique('players', 'name')->where('game_id', $this->game->id),
            ],
            'archetype' => ['required', Rule::in(array_keys(config('questcast.archetypes')))],
            'photo' => self::PHOTO_RULES,
        ], attributes: ['name' => 'name', 'photo' => 'photo']);

        $portrait = null;

        if ($this->photo) {
            try {
                $portrait = Portrait::store($this->photo);
            } catch (RuntimeException $e) {
                $this->addError('photo', $e->getMessage());

                return;
            }
        }

        try {
            $player = $engine->join($this->game, trim($this->name), $this->archetype, $portrait);
        } catch (RuntimeException $e) {
            // Do not leave an orphaned file behind if the seat was taken.
            Portrait::delete($portrait);
            $this->addError('name', $e->getMessage());

            return;
        }

        $this->photo = null;

        session()->put("player_token.{$this->game->code}", $player->token);

        $this->playerId = $player->id;
        $this->dispatch('sfx', name: 'join');
        $this->refreshTable();
    }

    public function choose(string $key, GameEngine $engine): void
    {
        $this->act($engine, optionKey: $key, freeText: null);
    }

    public function improvise(GameEngine $engine): void
    {
        $this->validate(['freeText' => 'required|string|min:3|max:200']);

        $this->act($engine, optionKey: null, freeText: $this->freeText);
    }

    private function act(GameEngine $engine, ?string $optionKey, ?string $freeText): void
    {
        $player = $this->player;
        $scene = $this->scene;

        if (! $player || ! $scene) {
            return;
        }

        try {
            $action = $engine->submitAction($player, $scene, $optionKey, $freeText);
        } catch (RuntimeException $e) {
            $this->addError('freeText', $e->getMessage());
            $this->dispatch('sfx', name: 'fail');

            return;
        }

        // Rattle the dice, then land the result once they have settled.
        $this->dispatch(
            'sfx',
            name: $action->roll === null ? 'select' : 'roll',
            then: match ($action->success) {
                true => 'success',
                false => 'fail',
                default => null,
            },
        );

        $this->freeText = '';
        $this->refreshTable();
    }

    /** Let a player change their mind while the scene is still open. */
    public function rethink(): void
    {
        if ($this->scene?->isOpen() && $this->action) {
            $this->action->delete();
            $this->refreshTable();
        }
    }

    public function render()
    {
        return view('livewire.player.table', [
            'archetypes' => config('questcast.archetypes'),
            'status' => $this->game->status,
            'canJoin' => $this->game->status === GameStatus::Lobby,
        ]);
    }
}
