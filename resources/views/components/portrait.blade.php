@props(['player', 'ring' => true])

{{--
    A player's face, or their initial when they skipped the camera. Characters
    who are down are drained of colour so the state reads at a glance from
    across the room.
--}}
<span {{ $attributes->class([
        'relative inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-parch-200 text-ink-900',
        'ring-2 ring-earth-400' => $ring && $player->isAlive(),
        'ring-2 ring-red-600/70 grayscale' => ! $player->isAlive(),
    ]) }}>
    @if ($player->hasPortrait())
        <img src="{{ $player->portraitUrl() }}" alt="{{ $player->displayName() }}"
             loading="lazy" class="size-full object-cover">
    @else
        <span class="text-sm font-semibold">{{ $player->initials() }}</span>
    @endif
</span>
