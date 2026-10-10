@php
    use App\Enums\GameStatus;
    use App\Enums\EventType;
@endphp

{{-- Broadcasts drive the screen; the slow poll is a safety net for a
     dropped websocket, which on a projector machine is a question of
     when rather than if. --}}
{{-- Only this screen speaks. The phones carry the same story but there is one
     room and one set of speakers, so the voice belongs to the TV. --}}
<div class="flex h-screen flex-col overflow-hidden" wire:poll.10s
     data-voice-over="{{ json_encode($this->voiceOver) }}">

    {{-- Status bar: always readable from across the room --}}
    <header class="flex flex-wrap items-center justify-between gap-4 border-b border-earth-300 px-8 py-4">
        <div>
            <h1 class="narration text-2xl font-semibold text-ink-900">{{ $game->title }}</h1>
            <p class="text-sm text-ink-500">
                {{ $game->status->label() }}
                @if ($game->scene_number > 0)
                    &middot; Scene {{ $game->scene_number }}
                @endif
            </p>
        </div>

        <div class="flex items-center gap-6">
            <div class="text-right">
                <p class="text-xs uppercase tracking-widest text-ink-500">Join code</p>
                <p class="narration text-3xl font-semibold tracking-[0.3em] text-sun-700">{{ $game->code }}</p>
            </div>

            <x-sound-toggle />

            @if ($game->status->isPlayable())
                <div class="flex gap-2">
                    @if ($game->status === GameStatus::Lobby)
                        <button wire:click="begin" wire:loading.attr="disabled"
                                @disabled($this->players->isEmpty())
                                class="rounded-lg bg-sun-400 px-4 py-2 text-sm font-semibold text-ink-900 transition hover:bg-sun-300 disabled:cursor-not-allowed disabled:opacity-40">
                            Begin the adventure
                        </button>
                    @elseif ($game->status === GameStatus::AwaitingActions)
                        <button wire:click="forceResolve" wire:loading.attr="disabled"
                                class="rounded-lg border border-earth-400 px-4 py-2 text-sm font-semibold text-ink-900 transition hover:border-sun-500 hover:text-sun-700">
                            Resolve now
                        </button>
                    @endif

                    <button wire:click="endGame" wire:confirm="End the session for everyone?"
                            class="rounded-lg border border-earth-300 px-4 py-2 text-sm text-ink-500 transition hover:border-red-600/70 hover:text-red-700">
                        End
                    </button>
                </div>
            @endif
        </div>
    </header>

    @if ($game->last_error)
        <div class="flex items-center justify-between gap-4 border-b border-red-600/55 bg-red-500/15 px-8 py-3">
            <p class="text-sm text-red-700">{{ $game->last_error }}</p>
            <button wire:click="retry"
                    class="shrink-0 rounded-lg border border-red-600/60 px-3 py-1.5 text-sm font-semibold text-red-800 transition hover:bg-red-500/25">
                Try again
            </button>
        </div>
    @endif

    @error('control')
        <div class="border-b border-amber-600/55 bg-amber-500/20 px-8 py-3 text-sm text-amber-800">{{ $message }}</div>
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
                        <p class="text-lg text-ink-500">Scan, or go to</p>
                        <p class="narration mt-1 text-3xl font-semibold text-ink-900">
                            {{ Str::after($this->joinUrl, '://') }}
                        </p>
                    </div>
                </div>

            @elseif (in_array($game->status, [GameStatus::Generating, GameStatus::Resolving], true))
                <div class="flex flex-1 flex-col items-center justify-center gap-6">
                    <div class="flex gap-2">
                        <span class="thinking-dot size-3 rounded-full bg-sun-400"></span>
                        <span class="thinking-dot size-3 rounded-full bg-sun-400"></span>
                        <span class="thinking-dot size-3 rounded-full bg-sun-400"></span>
                    </div>
                    <p class="narration text-2xl text-ink-500">
                        {{ $game->status === GameStatus::Generating
                            ? 'The dungeon master sets the scene…'
                            : 'The dungeon master weighs what you did…' }}
                    </p>

                    @if ($game->isStuck())
                        <button wire:click="retry"
                                class="rounded-lg border border-earth-400 px-4 py-2 text-sm text-ink-500 hover:border-sun-500 hover:text-sun-700">
                            Taking too long &mdash; try again
                        </button>
                    @endif
                </div>

            @else
                @if ($this->scene)
                    <article class="flex-1">
                        <p class="text-xs uppercase tracking-[0.3em] text-sun-700">
                            Scene {{ $this->scene->number }}
                        </p>
                        <p class="narration mt-4 text-pretty text-3xl leading-relaxed text-ink-900 xl:text-4xl">
                            {{ $this->scene->narration }}
                        </p>

                        @if ($this->scene->resolution)
                            <p class="narration mt-6 border-l-2 border-sun-500/70 pl-5 text-pretty text-xl leading-relaxed text-ink-500">
                                {{ $this->scene->resolution }}
                            </p>
                        @endif

                        @if ($this->scene->isOpen() && filled($this->scene->options))
                            <ul class="mt-8 grid gap-3 sm:grid-cols-2" data-testid="options">
                                @foreach ($this->scene->options as $option)
                                    <li class="rounded-xl border border-earth-300 bg-parch-50/85 px-5 py-4">
                                        <span class="narration text-lg text-ink-900">
                                            <span class="text-sun-700">{{ strtoupper($option['key']) }}.</span>
                                            {{ $option['label'] }}
                                        </span>
                                        @if ($option['skill'])
                                            <span class="mt-1 block text-xs uppercase tracking-wider text-ink-500">
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
                    <p class="narration mt-8 text-center text-2xl text-sun-700">
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
        <aside class="flex min-h-0 w-full shrink-0 flex-col gap-6 lg:w-1/3">
            <section class="shrink-0">
                <h2 class="text-xs uppercase tracking-[0.3em] text-ink-500">
                    The party ({{ $this->players->count() }})
                </h2>

                <ul class="mt-3 max-h-[34vh] space-y-2 overflow-y-auto pr-1" data-testid="party">
                    @forelse ($this->players as $player)
                        @php
                            $hasDecided = in_array($player->id, $this->decided, true);
                            $hpRatio = $player->max_hp > 0 ? $player->hp / $player->max_hp : 0;
                        @endphp
                        <li class="rounded-xl border px-4 py-3 transition
                                   {{ $player->isAlive() ? 'border-earth-300 bg-parch-50/85' : 'border-red-600/55 bg-red-500/10' }}">
                            <div class="flex items-center gap-2.5">
                                <x-portrait :player="$player" class="size-9" />
                                <div class="flex min-w-0 flex-1 items-baseline justify-between gap-2">
                                    <span class="truncate font-medium text-ink-900">{{ $player->displayName() }}</span>
                                    <span class="shrink-0 text-xs text-ink-500">{{ $player->archetype }}</span>
                                </div>
                            </div>

                            <div class="mt-2 flex items-center gap-2">
                                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-parch-200">
                                    <div class="h-full rounded-full transition-all
                                                {{ $hpRatio > 0.5 ? 'bg-grass-500' : ($hpRatio > 0.25 ? 'bg-amber-500' : 'bg-red-500') }}"
                                         style="width: {{ max(0, $hpRatio * 100) }}%"></div>
                                </div>
                                <span class="shrink-0 text-xs tabular-nums text-ink-500">
                                    {{ $player->hp }}/{{ $player->max_hp }}
                                </span>
                            </div>

                            @if ($game->status === GameStatus::AwaitingActions && $player->isAlive())
                                <p class="mt-2 text-xs {{ $hasDecided ? 'text-grass-600' : 'text-ink-500' }}">
                                    {{ $hasDecided ? 'Locked in' : 'Deciding…' }}
                                </p>
                            @endif

                            @if ($player->conditions)
                                <p class="mt-1 text-xs text-amber-700">{{ implode(', ', $player->conditions) }}</p>
                            @endif
                        </li>
                    @empty
                        <li class="rounded-xl border border-dashed border-earth-300 px-4 py-6 text-center text-sm text-ink-500">
                            Waiting for the first phone&hellip;
                        </li>
                    @endforelse
                </ul>
            </section>

            <section class="flex min-h-0 flex-1 flex-col">
                <h2 class="shrink-0 text-xs uppercase tracking-[0.3em] text-ink-500">Campaign log</h2>

                {{--
                    The log is read from across the room, so entries are shown
                    in full rather than clamped, and it takes whatever height
                    the roster leaves it instead of a fixed slice of the
                    viewport.

                    The newest entry is the one the room cares about, and it
                    arrives at the bottom, so the list pins itself there after
                    every update. Scrolling back unpins it, which keeps the
                    host's place while they read something older.
                --}}
                <ul x-data="{ pinned: true }"
                    x-init="
                        const list = $el, state = $data;
                        const pin = () => { if (state.pinned) { list.scrollTop = list.scrollHeight; } };
                        $nextTick(pin);
                        new MutationObserver(pin).observe(list, { childList: true, subtree: true, characterData: true });
                    "
                    x-on:scroll="pinned = $el.scrollHeight - $el.scrollTop - $el.clientHeight < 48"
                    class="mt-3 min-h-0 max-h-[32vh] flex-1 divide-y divide-earth-300/70 overflow-y-auto pr-2 text-sm lg:max-h-none lg:text-base"
                    data-testid="log">
                    @foreach ($this->log as $event)
                        <li wire:key="log-{{ $event->id }}"
                            class="py-2 {{ match ($event->type) {
                                EventType::Roll => 'text-ink-500 tabular-nums',
                                EventType::Damage => 'text-amber-700',
                                EventType::Death => 'font-semibold text-red-700',
                                EventType::Loot => 'text-grass-600',
                                EventType::Join => 'text-sun-700',
                                EventType::Narration => 'narration leading-relaxed text-ink-900',
                                EventType::Resolution => 'narration border-l-2 border-sun-500/70 pl-3 leading-relaxed text-ink-900',
                                default => 'text-ink-900',
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
    <footer class="shrink-0 border-t-4 border-grass-600/40 bg-grass-400/25 px-8 pb-7 pt-4">
        <div class="relative mx-auto h-28 max-w-6xl">

            {{-- The road, and how much of it is behind the party. The track is
                 drawn full width and the covered part painted over it, so the
                 route ahead is always visible as somewhere still to go. --}}
            <div class="road absolute inset-x-0 top-[5.1rem] h-2.5"></div>
            <div class="absolute left-0 top-[5.1rem] h-2.5 rounded-full bg-gradient-to-r from-sun-500 to-sun-400 transition-[width] duration-700 ease-out"
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

                {{-- Waypoints: a carved marker per scene, filled in as the
                     party passes it, and ringed while they stand on it. --}}
                <div class="absolute top-[4.45rem] -translate-x-1/2 text-center"
                     style="left: {{ $at }}%">
                    <span @class([
                        'mx-auto block size-4 rounded-full border-[3px] border-earth-500 transition-all duration-500',
                        'bg-sun-400' => $done,
                        'bg-sun-300 trail-now ring-4 ring-sun-400/70' => $now,
                        'bg-parch-50' => ! $done && ! $now,
                    ])></span>
                    <span @class([
                        'mt-2 block text-[0.7rem] font-semibold tabular-nums transition-colors',
                        'text-sun-700' => $now,
                        'text-ink-900' => $done,
                        'text-ink-500' => ! $done && ! $now,
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
                                    'ring-grass-500' => $decided && $player->isAlive(),
                                    'ring-sun-500/80' => ! $decided && $player->isAlive(),
                                    'ring-red-600/80 grayscale' => ! $player->isAlive(),
                                ])
                                style="animation-delay: {{ $index * 220 }}ms" />
                </div>
            @endforeach

            {{-- The adventurer leads. The wrapper carries him along the road;
                 the image itself does the bouncing, so the two never fight. --}}
            <div class="absolute bottom-10 z-10 transition-[left] duration-700 ease-out"
                 style="left: {{ $this->journey['percent'] }}%; transform: translateX(-50%);">
                <div aria-hidden="true"
                     class="absolute inset-x-1 bottom-1 h-3 rounded-full bg-sun-400/40 blur-md"></div>
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
