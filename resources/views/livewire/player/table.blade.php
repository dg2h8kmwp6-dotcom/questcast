@php
    use App\Enums\GameStatus;
@endphp

<div class="flex flex-1 flex-col" wire:poll.15s>

    {{-- Not yet seated: the join form --}}
    @if (! $this->player)
        @if (! $canJoin)
            <div class="flex flex-1 flex-col items-center justify-center gap-3 text-center">
                <p class="narration text-2xl text-white">This game is already underway.</p>
                <p class="text-sm text-deep-400">
                    Ask the host to start a new one, or watch over somebody&rsquo;s shoulder.
                </p>
            </div>
        @else
            <form wire:submit="join" class="flex flex-1 flex-col">
                <header class="pt-6 text-center">
                    <p class="text-xs uppercase tracking-[0.3em] text-gold-500">{{ $game->code }}</p>
                    <h1 class="narration mt-2 text-3xl font-semibold text-white">{{ $game->title }}</h1>
                </header>

                {{--
                    capture="user" opens the front camera straight away on a
                    phone, which is the whole point: the device they are about
                    to play on is also the camera. On a desktop browser the same
                    input politely falls back to a file picker.
                --}}
                <div class="mt-7 flex flex-col items-center">
                    <label for="photo" class="group cursor-pointer">
                        <span class="sr-only">Take your portrait</span>

                        <span class="springy relative flex size-28 items-center justify-center overflow-hidden rounded-full border-2 border-dashed border-deep-700 bg-deep-900 group-hover:border-gold-500">
                            @if ($photo && ! $errors->has('photo'))
                                <img src="{{ $photo->temporaryUrl() }}" alt="Your portrait"
                                     class="size-full object-cover">
                            @else
                                <svg class="size-9 text-deep-400 group-hover:text-gold-400" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="1.6"
                                     stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                                    <circle cx="12" cy="13" r="4"/>
                                </svg>
                            @endif

                            <span wire:loading wire:target="photo"
                                  class="absolute inset-0 flex items-center justify-center bg-deep-950/80 text-xs text-gold-300">
                                Developing&hellip;
                            </span>
                        </span>
                    </label>

                    <input id="photo" type="file" wire:model="photo"
                           accept="image/*" capture="user" class="sr-only">

                    <p class="mt-2 text-center text-sm text-deep-400">
                        @if ($photo && ! $errors->has('photo'))
                            Looking good.
                            <button type="button" wire:click="removePhoto"
                                    class="underline underline-offset-4 hover:text-gold-300">Retake</button>
                        @else
                            Snap a portrait for your character &mdash; or skip it.
                        @endif
                    </p>

                    @error('photo') <p class="mt-1.5 text-sm text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="mt-6">
                    <label for="name" class="block text-sm font-medium text-deep-200">Your name</label>
                    <input id="name" type="text" wire:model="name" maxlength="24"
                           autocomplete="given-name" autocapitalize="words" enterkeyhint="done"
                           class="tap mt-1.5 w-full rounded-xl border border-deep-700 bg-deep-900 px-4 text-lg text-white placeholder:text-deep-700 focus:border-gold-500 focus:outline-none focus:ring-1 focus:ring-gold-500"
                           placeholder="Ana">
                    @error('name') <p class="mt-1.5 text-sm text-red-400">{{ $message }}</p> @enderror
                </div>

                <fieldset class="mt-6 flex-1">
                    <legend class="block text-sm font-medium text-deep-200">Pick a character</legend>

                    <div class="mt-2 grid grid-cols-2 gap-2">
                        @foreach ($archetypes as $key => $stats)
                            <label class="tap flex cursor-pointer flex-col justify-center rounded-xl border px-3 py-2 transition
                                          {{ $archetype === $key ? 'border-gold-500 bg-gold-500/10' : 'border-deep-800' }}">
                                <input type="radio" wire:model.live="archetype" value="{{ $key }}" class="sr-only">
                                <span class="font-medium text-white">{{ $key }}</span>
                                <span class="mt-0.5 text-xs text-deep-400">
                                    @php
                                        $best = collect($stats)->sortDesc()->keys()->first();
                                    @endphp
                                    Best at {{ $best }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <button type="submit" wire:loading.attr="disabled"
                        class="tap mt-6 w-full rounded-xl bg-gold-500 text-lg font-semibold text-deep-950 transition hover:bg-gold-400 disabled:opacity-50">
                    <span wire:loading.remove wire:target="join">Join the party</span>
                    <span wire:loading wire:target="join">Joining&hellip;</span>
                </button>
            </form>
        @endif

    @else
        {{-- Seated: character card, then whatever the game needs from you --}}
        @php $player = $this->player; @endphp

        <header class="flex items-center justify-between gap-3 border-b border-deep-800 pb-3">
            <div class="flex min-w-0 items-center gap-2.5">
                <x-portrait :player="$player" class="size-10" />
                <div class="min-w-0">
                    <p class="truncate font-semibold text-white">{{ $player->displayName() }}</p>
                    <p class="text-xs text-deep-400">{{ $player->archetype }}</p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                @php $hpRatio = $player->max_hp > 0 ? $player->hp / $player->max_hp : 0; @endphp
                <div class="h-1.5 w-20 overflow-hidden rounded-full bg-deep-800">
                    <div class="h-full rounded-full transition-all
                                {{ $hpRatio > 0.5 ? 'bg-emerald-500' : ($hpRatio > 0.25 ? 'bg-amber-500' : 'bg-red-500') }}"
                         style="width: {{ max(0, $hpRatio * 100) }}%"></div>
                </div>
                <span class="text-sm tabular-nums text-deep-400">{{ $player->hp }}/{{ $player->max_hp }}</span>
            </div>
        </header>

        @if ($player->conditions)
            <p class="mt-2 text-xs text-amber-400">{{ implode(', ', $player->conditions) }}</p>
        @endif

        @if ($player->inventory)
            <p class="mt-1 text-xs text-deep-400">Carrying: {{ implode(', ', $player->inventory) }}</p>
        @endif

        <main class="flex flex-1 flex-col pt-5">
            @if (! $player->isAlive())
                <div class="flex flex-1 flex-col items-center justify-center gap-2 text-center">
                    <p class="narration text-2xl text-red-400">You are down.</p>
                    <p class="text-sm text-deep-400">Hope somebody picks you up.</p>
                </div>

            @elseif ($status === GameStatus::Lobby)
                <div class="flex flex-1 flex-col items-center justify-center gap-3 text-center">
                    <p class="narration text-2xl text-white">You&rsquo;re in.</p>
                    <p class="text-sm text-deep-400">Watch the big screen. The host starts when everyone&rsquo;s seated.</p>
                </div>

            @elseif ($status === GameStatus::Ended)
                <div class="flex flex-1 flex-col items-center justify-center gap-3 text-center">
                    <p class="narration text-2xl text-gold-400">
                        {{ match ($game->outcome) {
                            'victory' => 'You made it.',
                            'defeat' => 'You did not make it.',
                            default => 'That’s a wrap.',
                        } }}
                    </p>
                </div>

            @elseif (in_array($status, [GameStatus::Generating, GameStatus::Resolving], true))
                <div class="flex flex-1 flex-col items-center justify-center gap-5">
                    <div class="flex gap-2">
                        <span class="thinking-dot size-2.5 rounded-full bg-gold-500"></span>
                        <span class="thinking-dot size-2.5 rounded-full bg-gold-500"></span>
                        <span class="thinking-dot size-2.5 rounded-full bg-gold-500"></span>
                    </div>
                    <p class="text-center text-deep-400">Look up at the screen.</p>
                </div>

            @elseif ($this->scene && $this->action)
                {{-- Already decided: show the roll and wait --}}
                <div class="flex flex-1 flex-col items-center justify-center gap-4 text-center">
                    <p class="text-xs uppercase tracking-[0.3em] text-deep-400">You chose</p>
                    <p class="narration text-xl text-white">{{ $this->action->intent() }}</p>

                    @if ($summary = $this->action->rollSummary())
                        <p class="rounded-lg border px-4 py-2 text-sm tabular-nums
                                  {{ $this->action->success === false ? 'border-red-500/40 text-red-300' : 'border-emerald-500/40 text-emerald-300' }}">
                            {{ $summary }}
                        </p>
                    @endif

                    <p class="text-sm text-deep-400">
                        @if ($this->waitingOn > 0)
                            Waiting on {{ $this->waitingOn }} more
                            {{ Str::plural('player', $this->waitingOn) }}&hellip;
                        @else
                            Everyone&rsquo;s in.
                        @endif
                    </p>

                    <button wire:click="rethink"
                            class="mt-2 text-sm text-deep-400 underline underline-offset-4 hover:text-gold-300">
                        Change my mind
                    </button>
                </div>

            @elseif ($this->scene)
                {{-- Decide --}}
                <p class="text-xs uppercase tracking-[0.3em] text-gold-500">
                    Scene {{ $this->scene->number }} &middot; what do you do?
                </p>

                <div class="mt-3 space-y-2" data-testid="options">
                    @foreach ($this->scene->options as $option)
                        <button type="button" wire:click="choose('{{ $option['key'] }}')"
                                wire:loading.attr="disabled" wire:target="choose"
                                class="tap flex w-full items-center gap-3 rounded-xl border border-deep-800 bg-deep-900 px-4 text-left transition active:scale-[0.99] active:border-gold-500">
                            <span class="narration shrink-0 text-lg font-semibold text-gold-500">
                                {{ strtoupper($option['key']) }}
                            </span>
                            <span class="min-w-0 flex-1 py-3">
                                <span class="block text-white">{{ $option['label'] }}</span>
                                @if ($option['skill'])
                                    <span class="mt-0.5 block text-xs uppercase tracking-wider text-deep-400">
                                        {{ $option['skill'] }}
                                        {{ $player->modifierFor($option['skill']) >= 0 ? '+' : '' }}{{ $player->modifierFor($option['skill']) }}
                                        &middot; DC {{ $option['dc'] }}
                                    </span>
                                @endif
                            </span>
                        </button>
                    @endforeach
                </div>

                @if (data_get($game->settings, 'allow_free_text', true))
                    <form wire:submit="improvise" class="mt-5 border-t border-deep-800 pt-5">
                        <label for="freeText" class="block text-sm font-medium text-deep-200">
                            Or do something else
                        </label>
                        <textarea id="freeText" wire:model="freeText" rows="2" maxlength="200"
                                  enterkeyhint="send" placeholder="I try to bribe the guard&hellip;"
                                  class="mt-1.5 w-full resize-none rounded-xl border border-deep-700 bg-deep-900 px-4 py-3 text-white placeholder:text-deep-700 focus:border-gold-500 focus:outline-none focus:ring-1 focus:ring-gold-500"></textarea>
                        @error('freeText') <p class="mt-1.5 text-sm text-red-400">{{ $message }}</p> @enderror

                        <button type="submit" wire:loading.attr="disabled"
                                class="tap mt-2 w-full rounded-xl border border-deep-700 font-semibold text-white transition hover:border-gold-500 hover:text-gold-300">
                            Try it
                        </button>
                    </form>
                @endif
            @endif
        </main>
    @endif
</div>
