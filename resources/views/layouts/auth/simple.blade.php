<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-yak-cream antialiased">
        {{-- Background with geometric blobs and noise --}}
        <div class="fixed inset-0 overflow-hidden">
            <div class="absolute inset-0 bg-yak-cream"></div>
            {{-- Geometric blob shapes --}}
            <div class="absolute top-[-10%] left-[-5%] w-[40%] h-[40%] rounded-full bg-yak-blue/[0.07] blur-3xl"></div>
            <div class="absolute bottom-[-10%] right-[-5%] w-[35%] h-[35%] rounded-full bg-yak-orange/[0.08] blur-3xl"></div>
            <div class="absolute top-[30%] right-[10%] w-[25%] h-[25%] rounded-full bg-yak-green/[0.06] blur-3xl"></div>
            <div class="absolute bottom-[20%] left-[15%] w-[20%] h-[20%] rounded-full bg-yak-orange-warm/[0.05] blur-3xl"></div>
            {{-- Noise texture overlay --}}
            <div class="absolute inset-0 bg-noise"></div>
        </div>

        <div class="relative flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-6">
                {{-- Brand wordmark --}}
                <div class="flex flex-col items-center">
                    <h1 class="font-serif text-[42px] text-yak-slate tracking-tight leading-none">
                        Y<span class="italic text-yak-orange">a</span>k
                    </h1>
                </div>

                {{-- Login card with glass effect --}}
                <div class="glass elevation-2">
                    {{-- Mascot video --}}
                    <div class="overflow-hidden rounded-t-[28px]">
                        <video autoplay loop muted playsinline class="w-full h-48 object-cover">
                            <source src="{{ asset('videos/yak-v3-hair-lift-1.mp4') }}" type="video/mp4">
                        </video>
                    </div>

                    <div class="px-8 py-8">
                        {{ $slot }}
                    </div>
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
