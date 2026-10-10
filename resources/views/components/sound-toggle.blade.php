{{--
    Sound is on by default and can be killed from any screen; the choice is
    remembered per device. Rendered inline rather than pinned to a corner,
    because every screen here has a full-width control somewhere a floating
    button would sit on top of. Wired up in resources/js/audio.js.
--}}
<button type="button" data-sound-toggle data-silent
        aria-pressed="false" aria-label="Turn sound off"
        {{ $attributes->class([
            'springy flex size-10 shrink-0 items-center justify-center rounded-full',
            'border border-deep-700 bg-deep-900/70 text-deep-400',
            'hover:border-gold-500 hover:text-gold-300',
            'focus:outline-none focus:ring-2 focus:ring-gold-500',
        ]) }}>
    <span data-sound-on aria-hidden="true">
        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
            <path d="M11 5 6 9H2v6h4l5 4V5z"/>
            <path d="M15.5 8.5a5 5 0 0 1 0 7"/>
            <path d="M19 5a9 9 0 0 1 0 14"/>
        </svg>
    </span>
    <span data-sound-off hidden aria-hidden="true">
        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
            <path d="M11 5 6 9H2v6h4l5 4V5z"/>
            <path d="m23 9-6 6"/>
            <path d="m17 9 6 6"/>
        </svg>
    </span>
</button>
