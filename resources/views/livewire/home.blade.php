<div data-lobby-music
     class="relative mx-auto flex min-h-dvh w-full max-w-5xl flex-col justify-center gap-10 px-6 py-12">

    <x-sound-toggle class="absolute right-5 top-5" />

    <header class="flex flex-col items-center gap-6 text-center lg:flex-row lg:items-end lg:gap-12 lg:text-left">

        {{-- Our adventurer, already halfway out the door. The glow behind him
             keeps the cut-out from floating on the dark background. --}}
        <div class="relative shrink-0">
            <div aria-hidden="true"
                 class="absolute inset-x-0 bottom-0 h-2/3 rounded-full bg-sun-400/35 blur-3xl"></div>
            <img src="{{ asset('img/adventurer.png') }}"
                 alt="An adventurer in a wide-brimmed hat, striding off on a quest"
                 width="320" height="320" fetchpriority="high"
                 class="bobbing relative w-40 drop-shadow-2xl sm:w-48 lg:w-56">
        </div>

        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.3em] text-sun-700">
                Aaron&rsquo;s Adventures
            </p>
            <h1 class="narration mt-3 text-5xl font-semibold text-ink-900 sm:text-6xl">
                A world to cross.<br class="hidden sm:inline"> Everyone&rsquo;s phone.
            </h1>
            <p class="mx-auto mt-4 max-w-xl text-pretty text-ink-500 lg:mx-0">
                Put the map on the TV and let the room scan in. A storyteller runs
                the world, you decide where the party goes, and the dice are rolled
                honestly on the server.
            </p>
        </div>
    </header>

    <div class="grid gap-6 md:grid-cols-5">

        {{-- Host: create a new adventure --}}
        <form wire:submit="host"
              class="chunky md:col-span-3 border border-earth-300 bg-parch-100/90 p-6">
            <h2 class="text-lg font-semibold text-ink-900">Run an adventure</h2>
            <p class="mt-1 text-sm text-ink-500">This device becomes the map on the wall.</p>

            {{-- The slowest part of starting a game is an empty premise box, so
                 there is always something in it. Everything a pick fills in
                 stays editable. --}}
            <div class="mt-5">
                <span class="block text-sm font-medium text-ink-900">Start from</span>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($this->adventures as $index => $adventure)
                        <button type="button" wire:click="useAdventure({{ $index }})"
                                @class([
                                    'springy rounded-lg border px-3 py-1.5 text-sm transition',
                                    'border-sun-500 bg-sun-400/30 text-sun-700' => $chosen === $index,
                                    'border-earth-300 text-ink-500 hover:border-earth-500 hover:text-ink-900' => $chosen !== $index,
                                ])>
                            {{ $adventure['title'] }}
                        </button>
                    @endforeach

                    <button type="button" wire:click="writeMyOwn"
                            @class([
                                'springy rounded-lg border border-dashed px-3 py-1.5 text-sm transition',
                                'border-sun-500 text-sun-700' => $chosen === null,
                                'border-earth-300 text-ink-500 hover:border-earth-500 hover:text-ink-900' => $chosen !== null,
                            ])>
                        Write my own
                    </button>
                </div>
            </div>

            <div class="mt-5 space-y-4">
                <div>
                    <label for="title" class="block text-sm font-medium text-ink-900">Title</label>
                    <input id="title" type="text" wire:model="title" autocomplete="off"
                           class="mt-1.5 w-full rounded-lg border border-earth-400 bg-sky-200 px-3 py-2.5 text-ink-900 placeholder:text-earth-400 focus:border-sun-500 focus:outline-none focus:ring-1 focus:ring-sun-500">
                    @error('title') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="premise" class="block text-sm font-medium text-ink-900">Premise</label>
                    <textarea id="premise" wire:model="premise" rows="4"
                              class="mt-1.5 w-full resize-none rounded-lg border border-earth-400 bg-sky-200 px-3 py-2.5 text-ink-900 placeholder:text-earth-400 focus:border-sun-500 focus:outline-none focus:ring-1 focus:ring-sun-500"></textarea>
                    <p class="mt-1.5 text-xs text-ink-500">
                        The hook the storyteller builds from. A paragraph is plenty.
                    </p>
                    @error('premise') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <fieldset>
                    <legend class="block text-sm font-medium text-ink-900">
                        Tone <span class="font-normal text-ink-500">&middot; optional</span>
                    </legend>

                    <label class="mt-2 flex cursor-pointer items-start gap-2.5 rounded-lg border p-3 transition
                                  {{ $tone === \App\Models\Game::TONE_AUTO ? 'border-sun-500 bg-sun-400/30' : 'border-earth-300 hover:border-earth-500' }}">
                        <input type="radio" wire:model.live="tone" value="{{ \App\Models\Game::TONE_AUTO }}"
                               class="mt-0.5 accent-sun-500">
                        <span>
                            <span class="block text-sm font-medium text-ink-900">Let the storyteller decide</span>
                            <span class="block text-xs text-ink-500">
                                The tone is read off your premise before the first scene is written.
                            </span>
                        </span>
                    </label>

                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach ($tones as $key => $description)
                            <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border p-3 transition
                                          {{ $tone === $key ? 'border-sun-500 bg-sun-400/30' : 'border-earth-300 hover:border-earth-500' }}">
                                <input type="radio" wire:model.live="tone" value="{{ $key }}"
                                       class="mt-0.5 accent-sun-500">
                                <span>
                                    <span class="block text-sm font-medium capitalize text-ink-900">{{ $key }}</span>
                                    <span class="block text-xs text-ink-500">{{ $description }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            </div>

            <button type="submit"
                    class="springy mt-6 w-full rounded-xl bg-sun-400 px-4 py-3 font-semibold text-ink-900 hover:bg-sun-300 focus:outline-none focus:ring-2 focus:ring-sun-400 focus:ring-offset-2 focus:ring-offset-parch-100">
                <span wire:loading.remove wire:target="host">Set out</span>
                <span wire:loading wire:target="host">Shouldering packs&hellip;</span>
            </button>
        </form>

        {{-- Player: join with a code --}}
        <form wire:submit="joinExisting"
              class="chunky md:col-span-2 flex flex-col border border-earth-300 bg-parch-50/70 p-6">
            <h2 class="text-lg font-semibold text-ink-900">Join the party</h2>
            <p class="mt-1 text-sm text-ink-500">Type the code on the screen.</p>

            <label for="code" class="sr-only">Game code</label>
            <input id="code" type="text" wire:model="code" maxlength="5"
                   inputmode="latin" autocapitalize="characters" autocomplete="off" spellcheck="false"
                   placeholder="QUEST"
                   class="narration mt-5 w-full rounded-lg border border-earth-400 bg-sky-200 px-3 py-4 text-center text-3xl font-semibold uppercase tracking-[0.4em] text-ink-900 placeholder:text-earth-400 focus:border-sun-500 focus:outline-none focus:ring-1 focus:ring-sun-500">
            @error('code') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror

            <button type="submit"
                    class="springy mt-4 w-full rounded-xl border border-earth-400 px-4 py-3 font-semibold text-ink-900 hover:border-sun-500 hover:text-sun-700 focus:outline-none focus:ring-2 focus:ring-sun-500">
                Take a seat
            </button>

            <p class="mt-auto pt-6 text-xs text-earth-400">
                No account needed. Your character lives in this browser.
            </p>
        </form>
    </div>
</div>
