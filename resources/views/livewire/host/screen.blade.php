@php
    use App\Enums\GameStatus;
    use App\Enums\EventType;
@endphp

{{-- Broadcasts drive the screen; the slow poll is a safety net for a
     dropped websocket, which on a projector machine is a question of
     when rather than if. --}}
<div class="flex h-screen flex-col overflow-hidden" wire:poll.10s>

    {{-- Status bar: always readable from across the room --}}
    <header class="flex flex-wrap items-center justify-between gap-4 border-b border-deep-800 px-8 py-4">
        <div>
            <h1 class="narration text-2xl font-semibold text-white">{{ $game->title }}</h1>
            <p class="text-sm text-deep-400">
                {{ $game->status->label() }}
                @if ($game->scene_number > 0)
                    &middot; Scene {{ $game->scene_number }}
                @endif
            </p>
        </div>

        <div class="flex items-center gap-6">
            <div class="text-right">
                <p class="text-xs uppercase tracking-widest text-deep-400">Join code</p>
                <p class="narration text-3xl font-semibold tracking-[0.3em] text-gold-400">{{ $game->code }}</p>
            </div>

            <x-sound-toggle />

            @if ($game->status->isPlayable())
                <div class="flex gap-2">
                    @if ($game->status === GameStatus::Lobby)
                        <button wire:click="begin" wire:loading.attr="disabled"
                                @disabled($this->players->isEmpty())
                                class="rounded-lg bg-gold-500 px-4 py-2 text-sm font-semibold text-deep-950 transition hover:bg-gold-400 disabled:cursor-not-allowed disabled:opacity-40">
                            Begin the adventure
                        </button>
                    @elseif ($game->status === GameStatus::AwaitingActions)
                        <button wire:click="forceResolve" wire:loading.attr="disabled"
                                class="rounded-lg border border-deep-700 px-4 py-2 text-sm font-semibold text-white transition hover:border-gold-500 hover:text-gold-300">
                            Resolve now
                        </button>
                    @endif

                    <button wire:click="endGame" wire:confirm="End the session for everyone?"
                            class="rounded-lg border border-deep-800 px-4 py-2 text-sm text-deep-400 transition hover:border-red-500/60 hover:text-red-400">
                        End
                    </button>
                </div>
            @endif
        </div>
    </header>

    @if ($game->last_error)
        <div class="flex items-center justify-between gap-4 border-b border-red-500/30 bg-red-500/10 px-8 py-3">
            <p class="text-sm text-red-300">{{ $game->last_error }}</p>
            <button wire:click="retry"
                    class="shrink-0 rounded-lg border border-red-500/40 px-3 py-1.5 text-sm font-semibold text-red-200 transition hover:bg-red-500/20">
                Try again
            </button>
        </div>
    @endif

    @error('control')
        <div class="border-b border-amber-500/30 bg-amber-500/10 px-8 py-3 text-sm text-amber-200">{{ $message }}</div>
    @enderror

    <div class="flex min-h-0 flex-1 flex-col gap-8 overflow-hidden p-8 lg:flex-row">

        {{-- Stage: the QR in the lobby, the story once it is running --}}
        <main class="flex min-h-0 flex-1 flex-col overflow-y-auto">
            @if ($game->status === GameStatus::Lobby)
                <div class="flex flex-1 flex-col items-center justify-center gap-8 text-center">
                    <div class="rounded-2xl bg-white p-4 shadow-2xl">
                        {!! $this->qr !!}
                    </div>
                    <div>
                        <p class="text-lg text-deep-400">Scan, or go to</p>
                        <p class="narration mt-1 text-3xl font-semibold text-white">
                            {{ Str::after($this->joinUrl, '://') }}
                        </p>
                    </div>
                </div>

            @elseif (in_array($game->status, [GameStatus::Generating, GameStatus::Resolving], true))
                <div class="flex flex-1 flex-col items-center justify-center gap-6">
                    <div class="flex gap-2">
                        <span class="thinking-dot size-3 rounded-full bg-gold-500"></span>
                        <span class="thinking-dot size-3 rounded-full bg-gold-500"></span>
                        <span class="thinking-dot size-3 rounded-full bg-gold-500"></span>
                    </div>
                    <p class="narration text-2xl text-deep-400">
                        {{ $game->status === GameStatus::Generating
                            ? 'The dungeon master sets the scene…'
                            : 'The dungeon master weighs what you did…' }}
                    </p>

                    @if ($game->isStuck())
                        <button wire:click="retry"
                                class="rounded-lg border border-deep-700 px-4 py-2 text-sm text-deep-400 hover:border-gold-500 hover:text-gold-300">
                            Taking too long &mdash; try again
                        </button>
                    @endif
                </div>

            @else
                @if ($this->scene)
                    <article class="flex-1">
                        <p class="text-xs uppercase tracking-[0.3em] text-gold-500">
                            Scene {{ $this->scene->number }}
                        </p>
                        <p class="narration mt-4 text-pretty text-3xl leading-relaxed text-deep-200 xl:text-4xl">
                            {{ $this->scene->narration }}
                        </p>

                        @if ($this->scene->resolution)
                            <p class="narration mt-6 border-l-2 border-gold-500/40 pl-5 text-pretty text-xl leading-relaxed text-deep-400">
                                {{ $this->scene->resolution }}
                            </p>
                        @endif

                        @if ($this->scene->isOpen() && filled($this->scene->options))
                            <ul class="mt-8 grid gap-3 sm:grid-cols-2" data-testid="options">
                                @foreach ($this->scene->options as $option)
                                    <li class="rounded-xl border border-deep-800 bg-deep-900/60 px-5 py-4">
                                        <span class="narration text-lg text-white">
                                            <span class="text-gold-500">{{ strtoupper($option['key']) }}.</span>
                                            {{ $option['label'] }}
                                        </span>
                                        @if ($option['skill'])
                                            <span class="mt-1 block text-xs uppercase tracking-wider text-deep-400">
                                                {{ $option['skill'] }} &middot; DC {{ $option['dc'] }}
                                            </span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </article>
                @endif

                @if ($game->status === GameStatus::Ended)
                    <p class="narration mt-8 text-center text-2xl text-gold-400">
                        {{ match ($game->outcome) {
                            'victory' => 'The party prevails.',
                            'defeat' => 'The party falls.',
                            default => 'The session is closed.',
                        } }}
                    </p>
                @endif
            @endif
        </main>

        {{-- Party roster and the campaign log --}}
        <aside class="flex min-h-0 w-full shrink-0 flex-col gap-6 lg:w-80">
            <section class="shrink-0">
                <h2 class="text-xs uppercase tracking-[0.3em] text-deep-400">
                    The party ({{ $this->players->count() }})
                </h2>

                <ul class="mt-3 max-h-[34vh] space-y-2 overflow-y-auto pr-1" data-testid="party">
                    @forelse ($this->players as $player)
                        @php
                            $hasDecided = in_array($player->id, $this->decided, true);
                            $hpRatio = $player->max_hp > 0 ? $player->hp / $player->max_hp : 0;
                        @endphp
                        <li class="rounded-xl border px-4 py-3 transition
                                   {{ $player->isAlive() ? 'border-deep-800 bg-deep-900/60' : 'border-red-500/30 bg-red-500/5' }}">
                            <div class="flex items-center gap-2.5">
                                <x-portrait :player="$player" class="size-9" />
                                <div class="flex min-w-0 flex-1 items-baseline justify-between gap-2">
                                    <span class="truncate font-medium text-white">{{ $player->displayName() }}</span>
                                    <span class="shrink-0 text-xs text-deep-400">{{ $player->archetype }}</span>
                                </div>
                            </div>

                            <div class="mt-2 flex items-center gap-2">
                                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-deep-800">
                                    <div class="h-full rounded-full transition-all
                                                {{ $hpRatio > 0.5 ? 'bg-emerald-500' : ($hpRatio > 0.25 ? 'bg-amber-500' : 'bg-red-500') }}"
                                         style="width: {{ max(0, $hpRatio * 100) }}%"></div>
                                </div>
                                <span class="shrink-0 text-xs tabular-nums text-deep-400">
                                    {{ $player->hp }}/{{ $player->max_hp }}
                                </span>
                            </div>

                            @if ($game->status === GameStatus::AwaitingActions && $player->isAlive())
                                <p class="mt-2 text-xs {{ $hasDecided ? 'text-emerald-400' : 'text-deep-400' }}">
                                    {{ $hasDecided ? 'Locked in' : 'Deciding…' }}
                                </p>
                            @endif

                            @if ($player->conditions)
                                <p class="mt-1 text-xs text-amber-400">{{ implode(', ', $player->conditions) }}</p>
                            @endif
                        </li>
                    @empty
                        <li class="rounded-xl border border-dashed border-deep-800 px-4 py-6 text-center text-sm text-deep-400">
                            Waiting for the first phone&hellip;
                        </li>
                    @endforelse
                </ul>
            </section>

            <section class="flex min-h-0 flex-1 flex-col">
                <h2 class="text-xs uppercase tracking-[0.3em] text-deep-400">Campaign log</h2>

                <ul class="mt-3 max-h-[30vh] space-y-1.5 overflow-y-auto pr-1 text-sm" data-testid="log">
                    @foreach ($this->log as $event)
                        <li class="{{ match ($event->type) {
                            EventType::Roll => 'text-deep-400 tabular-nums',
                            EventType::Damage => 'text-amber-400',
                            EventType::Death => 'text-red-400',
                            EventType::Loot => 'text-emerald-400',
                            EventType::Join => 'text-gold-400',
                            EventType::Narration, EventType::Resolution => 'text-deep-700 line-clamp-2',
                            default => 'text-deep-400',
                        } }}">
                            {{ $event->body }}
                        </li>
                    @endforeach
                </ul>
            </section>
        </aside>
    </div>

    {{--
        The journey strip. This is the thing the whole room watches: the
        adventurer stands on the scene the party is playing, and walks to the
        next marker when the storyteller opens one. Movement is a CSS
        transition on `left`, so a Livewire re-render is all it takes to send
        him down the road — no animation code, and it cannot desync from the
        actual scene number.
    --}}
    <footer class="shrink-0 border-t border-deep-800 px-8 pb-7 pt-4">
        <div class="relative mx-auto h-28 max-w-6xl">

            {{-- The road, and how much of it is behind the party --}}
            <div class="absolute inset-x-0 top-[5.25rem] h-1.5 rounded-full bg-deep-800"></div>
            <div class="absolute left-0 top-[5.25rem] h-1.5 rounded-full bg-gradient-to-r from-gold-500/70 to-gold-400 transition-[width] duration-700 ease-out"
                 style="width: {{ $this->journey['percent'] }}%"></div>

            {{-- One marker per scene --}}
            @for ($i = 1; $i <= $this->journey['total']; $i++)
                @php
                    $at = $this->journey['total'] > 1
                        ? ($i - 1) / ($this->journey['total'] - 1) * 100
                        : 0;
                    $done = $this->journey['started'] && $i < $this->journey['current'];
                    $now = $this->journey['started'] && $i === $this->journey['current'];
                @endphp

                <div class="absolute top-[4.6rem] -translate-x-1/2 text-center"
                     style="left: {{ $at }}%">
                    <span @class([
                        'block size-3 rounded-full transition-colors duration-500',
                        'bg-gold-400' => $done,
                        'bg-gold-300 trail-now' => $now,
                        'bg-deep-700' => ! $done && ! $now,
                    ])></span>
                    <span @class([
                        'mt-2 block text-[0.65rem] tabular-nums transition-colors',
                        'text-gold-300' => $now,
                        'text-deep-400' => $done,
                        'text-deep-700' => ! $done && ! $now,
                    ])>{{ $i }}</span>
                </div>
            @endfor

            {{-- The party, strung out behind the leader. Each face trails a
                 little further back and bobs on its own beat, so the line
                 reads as a group walking rather than a row of icons. --}}
            @foreach ($this->players as $index => $player)
                @php
                    // Tuck the line in behind him, and keep it on the road.
                    $behind = max(0, $this->journey['percent'] - 3.2 - $index * 2.6);
                    $decided = in_array($player->id, $this->decided, true);
                @endphp
                <div class="absolute bottom-11 transition-[left] duration-700 ease-out"
                     style="left: {{ $behind }}%; transform: translateX(-50%); transition-delay: {{ $index * 90 }}ms"
                     wire:key="walker-{{ $player->id }}">
                    <x-portrait :player="$player" :ring="false"
                                @class([
                                    'bobbing size-9 shadow-lg ring-2',
                                    'ring-emerald-400' => $decided && $player->isAlive(),
                                    'ring-gold-400/70' => ! $decided && $player->isAlive(),
                                    'ring-red-500/70 grayscale' => ! $player->isAlive(),
                                ])
                                style="animation-delay: {{ $index * 220 }}ms" />
                </div>
            @endforeach

            {{-- The adventurer leads. The wrapper carries him along the road;
                 the image itself does the bouncing, so the two never fight. --}}
            <div class="absolute bottom-10 z-10 transition-[left] duration-700 ease-out"
                 style="left: {{ $this->journey['percent'] }}%; transform: translateX(-50%);">
                <div aria-hidden="true"
                     class="absolute inset-x-1 bottom-1 h-3 rounded-full bg-gold-500/25 blur-md"></div>
                <img src="{{ asset('img/adventurer.png') }}"
                     alt="{{ $game->status === GameStatus::Ended
                            ? 'The adventurer celebrating at the end of the road'
                            : 'The adventurer standing at scene '.$this->journey['current'] }}"
                     width="320" height="320"
                     @class([
                         'relative w-20 drop-shadow-2xl',
                         'cheering' => $game->status === GameStatus::Ended,
                         'bobbing-fast' => in_array($game->status, [GameStatus::Generating, GameStatus::Resolving], true),
                         'bobbing' => ! in_array($game->status, [GameStatus::Generating, GameStatus::Resolving, GameStatus::Ended], true),
                     ])>
            </div>
        </div>
    </footer>
</div>
