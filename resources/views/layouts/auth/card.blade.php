<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-yak-cream antialiased">
        {{-- Background with geometric blobs and noise --}}
        <div class="fixed inset-0 overflow-hidden">
            <div class="absolute inset-0 bg-yak-cream"></div>
            <div class="absolute top-[-10%] left-[-5%] w-[40%] h-[40%] rounded-full bg-yak-blue/[0.07] blur-3xl"></div>
            <div class="absolute bottom-[-10%] right-[-5%] w-[35%] h-[35%] rounded-full bg-yak-orange/[0.08] blur-3xl"></div>
            <div class="absolute top-[30%] right-[10%] w-[25%] h-[25%] rounded-full bg-yak-green/[0.06] blur-3xl"></div>
            <div class="absolute inset-0 bg-noise"></div>
        </div>

        <div class="relative flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-md flex-col gap-6">
                <div class="flex flex-col items-center">
                    <h1 class="font-serif text-[42px] text-yak-slate tracking-tight leading-none">
                        Y<span class="italic text-yak-orange">a</span>k
                    </h1>
                </div>

                <div class="glass elevation-2">
                    <div class="px-10 py-8">{{ $slot }}</div>
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
