<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        <title>
            {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
        </title>

        <x-app-icons />

        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-app antialiased">
        <div class="relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
            <div class="w-full max-w-[460px]">
                <div class="overflow-hidden rounded-card border border-hair bg-panel shadow-card">
                    {{-- Mascot video --}}
                    <div class="relative aspect-video overflow-hidden bg-panel-2">
                        <video autoplay muted playsinline class="h-full w-full object-cover">
                            <source src="{{ asset('videos/yak-v3-hair-lift-1.mp4') }}" type="video/mp4">
                        </video>
                    </div>

                    <div class="px-9 pt-8 pb-10 text-center">
                        {{ $slot }}
                    </div>
                </div>
            </div>
        </div>
    </body>
</html>
