@props(['player', 'ring' => true])

{{--
    A player's face, or their initial when they skipped the camera. Characters
    who are down are drained of colour so the state reads at a glance from
    across the room.
--}}
<span {{ $attributes->class([
        'relative inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-deep-800 text-deep-200',
        'ring-2 ring-deep-700' => $ring && $player->isAlive(),
        'ring-2 ring-red-500/60 grayscale' => ! $player->isAlive(),
    ]) }}>
    @if ($player->hasPortrait())
        <img src="{{ $player->portraitUrl() }}" alt="{{ $player->displayName() }}"
             loading="lazy" class="size-full object-cover">
    @else
        <span class="text-sm font-semibold">{{ $player->initials() }}</span>
    @endif
</span>
