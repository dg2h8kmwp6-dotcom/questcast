<?php

namespace App\Livewire;

use App\Models\Game;
use Illuminate\Validation\Rule;
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

    public string $tone = 'sunlit';

    public string $code = '';

    public function mount(): void
    {
        $this->title = 'The Clockwork Atoll';
        $this->premise = 'Every chart says the atoll is empty. The brass gulls say otherwise — '
            .'they wheel over the lagoon at the same hour each day and never once land. '
            .'Your boat is pulled up on the sand, and the tide is going out.';
    }

    public function host()
    {
        $this->validate([
            'title' => 'required|string|min:3|max:80',
            'premise' => 'required|string|min:20|max:600',
            'tone' => ['required', Rule::in(array_keys(config('questcast.tones')))],
        ]);

        $game = Game::start($this->title, $this->premise, $this->tone);

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
