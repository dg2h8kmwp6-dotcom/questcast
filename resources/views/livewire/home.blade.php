<div data-lobby-music
     class="relative mx-auto flex min-h-dvh w-full max-w-5xl flex-col justify-center gap-10 px-6 py-12">

    <x-sound-toggle class="absolute right-5 top-5" />

    <header class="flex flex-col items-center gap-6 text-center lg:flex-row lg:items-end lg:gap-12 lg:text-left">

        {{-- Our adventurer, already halfway out the door. The glow behind him
             keeps the cut-out from floating on the dark background. --}}
        <div class="relative shrink-0">
            <div aria-hidden="true"
                 class="absolute inset-x-0 bottom-0 h-2/3 rounded-full bg-gold-500/20 blur-3xl"></div>
            <img src="{{ asset('img/adventurer.png') }}"
                 alt="An adventurer in a wide-brimmed hat, striding off on a quest"
                 width="320" height="320" fetchpriority="high"
                 class="bobbing relative w-40 drop-shadow-2xl sm:w-48 lg:w-56">
        </div>

        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.3em] text-gold-500">
                Aaron&rsquo;s Adventures
            </p>
            <h1 class="narration mt-3 text-5xl font-semibold text-white sm:text-6xl">
                Islands to cross.<br class="hidden sm:inline"> Everyone&rsquo;s phone.
            </h1>
            <p class="mx-auto mt-4 max-w-xl text-pretty text-deep-400 lg:mx-0">
                Put the map on the TV and let the room scan in. A storyteller runs
                the island, you decide what the party does, and the dice are rolled
                honestly on the server.
            </p>
        </div>
    </header>

    <div class="grid gap-6 md:grid-cols-5">

        {{-- Host: create a new adventure --}}
        <form wire:submit="host"
              class="chunky md:col-span-3 border border-deep-800 bg-deep-900/70 p-6">
            <h2 class="text-lg font-semibold text-white">Run an adventure</h2>
            <p class="mt-1 text-sm text-deep-400">This device becomes the map on the wall.</p>

            <div class="mt-5 space-y-4">
                <div>
                    <label for="title" class="block text-sm font-medium text-deep-200">Title</label>
                    <input id="title" type="text" wire:model="title" autocomplete="off"
                           class="mt-1.5 w-full rounded-lg border border-deep-700 bg-deep-950 px-3 py-2.5 text-white placeholder:text-deep-700 focus:border-gold-500 focus:outline-none focus:ring-1 focus:ring-gold-500">
                    @error('title') <p class="mt-1.5 text-sm text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="premise" class="block text-sm font-medium text-deep-200">Premise</label>
                    <textarea id="premise" wire:model="premise" rows="4"
                              class="mt-1.5 w-full resize-none rounded-lg border border-deep-700 bg-deep-950 px-3 py-2.5 text-white placeholder:text-deep-700 focus:border-gold-500 focus:outline-none focus:ring-1 focus:ring-gold-500"></textarea>
                    <p class="mt-1.5 text-xs text-deep-400">
                        The hook the storyteller builds from. A paragraph is plenty.
                    </p>
                    @error('premise') <p class="mt-1.5 text-sm text-red-400">{{ $message }}</p> @enderror
                </div>

                <fieldset>
                    <legend class="block text-sm font-medium text-deep-200">Tone</legend>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach ($tones as $key => $description)
                            <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border p-3 transition
                                          {{ $tone === $key ? 'border-gold-500 bg-gold-500/10' : 'border-deep-800 hover:border-deep-700' }}">
                                <input type="radio" wire:model.live="tone" value="{{ $key }}"
                                       class="mt-0.5 accent-gold-500">
                                <span>
                                    <span class="block text-sm font-medium capitalize text-white">{{ $key }}</span>
                                    <span class="block text-xs text-deep-400">{{ $description }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            </div>

            <button type="submit"
                    class="springy mt-6 w-full rounded-xl bg-gold-500 px-4 py-3 font-semibold text-deep-950 hover:bg-gold-400 focus:outline-none focus:ring-2 focus:ring-gold-300 focus:ring-offset-2 focus:ring-offset-deep-900">
                <span wire:loading.remove wire:target="host">Set sail</span>
                <span wire:loading wire:target="host">Casting off&hellip;</span>
            </button>
        </form>

        {{-- Player: join with a code --}}
        <form wire:submit="joinExisting"
              class="chunky md:col-span-2 flex flex-col border border-deep-800 bg-deep-900/40 p-6">
            <h2 class="text-lg font-semibold text-white">Join the party</h2>
            <p class="mt-1 text-sm text-deep-400">Type the code on the screen.</p>

            <label for="code" class="sr-only">Game code</label>
            <input id="code" type="text" wire:model="code" maxlength="5"
                   inputmode="latin" autocapitalize="characters" autocomplete="off" spellcheck="false"
                   placeholder="ATOLL"
                   class="narration mt-5 w-full rounded-lg border border-deep-700 bg-deep-950 px-3 py-4 text-center text-3xl font-semibold uppercase tracking-[0.4em] text-white placeholder:text-deep-800 focus:border-gold-500 focus:outline-none focus:ring-1 focus:ring-gold-500">
            @error('code') <p class="mt-2 text-sm text-red-400">{{ $message }}</p> @enderror

            <button type="submit"
                    class="springy mt-4 w-full rounded-xl border border-deep-700 px-4 py-3 font-semibold text-white hover:border-gold-500 hover:text-gold-300 focus:outline-none focus:ring-2 focus:ring-gold-500">
                Take a seat
            </button>

            <p class="mt-auto pt-6 text-xs text-deep-700">
                No account needed. Your character lives in this browser.
            </p>
        </form>
    </div>
</div>
