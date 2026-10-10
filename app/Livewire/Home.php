<?php

namespace App\Livewire;

use App\Jobs\PlanAdventure;
use App\Models\Game;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Questcast')]
class Home extends Component
{
    #[Validate('required|string|min:3|max:80')]
    public string $title = '';

    #[Validate('required|string|min:20|max:600')]
    public string $premise = '';

    /** A real tone key, or "auto" to have one read off the premise. */
    public string $tone = Game::TONE_AUTO;

    /** Which shelf adventure this started from, for the highlight. */
    public ?int $chosen = null;

    public string $code = '';

    public function mount(): void
    {
        $this->useAdventure(0);
    }

    /** @return array<int, array{title: string, premise: string, tone: ?string}> */
    #[Computed]
    public function adventures(): array
    {
        return config('questcast.adventures');
    }

    /** Fill the form from the shelf. Everything stays editable afterwards. */
    public function useAdventure(int $index): void
    {
        $adventure = $this->adventures[$index] ?? null;

        if (! $adventure) {
            return;
        }

        $this->chosen = $index;
        $this->title = $adventure['title'];
        $this->premise = $adventure['premise'];
        $this->tone = $adventure['tone'] ?? Game::TONE_AUTO;

        $this->resetErrorBag();
    }

    /** Clear the decks for somebody with their own idea. */
    public function writeMyOwn(): void
    {
        $this->chosen = null;
        $this->title = '';
        $this->premise = '';
        $this->tone = Game::TONE_AUTO;

        $this->resetErrorBag();
    }

    public function host()
    {
        $this->validate([
            'title' => 'required|string|min:3|max:80',
            'premise' => 'required|string|min:20|max:600',
            'tone' => [
                'required',
                Rule::in([...array_keys(config('questcast.tones')), Game::TONE_AUTO]),
            ],
        ]);

        $game = Game::start($this->title, $this->premise, $this->tone);

        // Lay the arc out now rather than at the whistle: the lobby wait is
        // dead time, and by the time the host presses begin the safety net is
        // usually already there.
        PlanAdventure::dispatch($game);

        // The host screen is identified by a secret in its own session, so a
        // player who learns the room code still cannot drive the game.
        session()->put("host_token.{$game->code}", $game->host_token);

        return $this->redirectRoute('host', $game->code, navigate: true);
    }

    public function joinExisting()
    {
        $this->validate([
            'code' => 'required|string|size:5',
        ]);

        $code = strtoupper(trim($this->code));

        if (! Game::where('code', $code)->exists()) {
            $this->addError('code', 'No game is running with that code.');

            return null;
        }

        return $this->redirectRoute('play', $code, navigate: true);
    }

    public function render()
    {
        return view('livewire.home', [
            'tones' => config('questcast.tones'),
        ]);
    }
}
