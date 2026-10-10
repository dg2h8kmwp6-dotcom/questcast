<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    {{-- No zoom: a mis-tap should never shift the whole board mid-decision. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0b0b0f">
    <meta name="mobile-web-app-capable" content="yes">
    <title>{{ $title ?? config('app.name') }}</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>&#127757;</text></svg>">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-deep-950 text-deep-200 antialiased select-none">
    <div class="mx-auto flex min-h-dvh w-full max-w-md flex-col px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-[max(1rem,env(safe-area-inset-top))]">
        <div class="flex justify-end pb-2">
            <x-sound-toggle class="size-9" />
        </div>

        {{ $slot }}
    </div>
</body>
</html>
