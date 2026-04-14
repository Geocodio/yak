<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-yak-cream antialiased">
        <div class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0">
            <div class="relative hidden h-full flex-col p-10 text-white lg:flex">
                <div class="absolute inset-0 bg-yak-slate dark:bg-[#12171e]"></div>
                <a href="{{ route('home') }}" class="relative z-20 flex items-center text-lg font-medium" wire:navigate>
                    <span class="font-serif text-3xl tracking-tight">
                        Y<span class="italic text-yak-orange">a</span>k
                    </span>
                </a>

                <div class="relative z-20 mt-auto">
                    <blockquote class="space-y-2">
                        <p class="text-lg text-yak-cream dark:text-[#f5f0e8]">&ldquo;Your autonomous coding companion.&rdquo;</p>
                    </blockquote>
                </div>
            </div>
            <div class="w-full lg:p-8">
                <div class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]">
                    <div class="flex flex-col items-center lg:hidden">
                        <h1 class="font-serif text-[42px] text-yak-slate tracking-tight leading-none">
                            Y<span class="italic text-yak-orange">a</span>k
                        </h1>
                    </div>
                    {{ $slot }}
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
