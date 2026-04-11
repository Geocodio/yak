<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-yak-cream bg-noise text-yak-slate">
        <div class="flex min-h-screen">
            {{-- Sidebar --}}
            <aside class="hidden lg:flex lg:w-60 lg:flex-col lg:fixed lg:inset-y-0 bg-yak-cream-dark border-r border-yak-tan/40">
                <div class="flex flex-col h-full">
                    {{-- Brand Wordmark --}}
                    <div class="px-6 py-6">
                        <a href="{{ route('dashboard') }}" wire:navigate>
                            <h1 class="font-serif text-[42px] text-yak-slate tracking-tight leading-none">
                                Y<span class="italic text-yak-orange">a</span>k
                            </h1>
                        </a>
                    </div>

                    {{-- Navigation --}}
                    <nav class="flex-1 px-3 space-y-1">
                        <a href="{{ route('tasks') }}"
                           class="flex items-center gap-3 px-3 py-2.5 text-sm rounded-btn transition-colors {{ request()->routeIs('tasks*') ? 'bg-yak-orange/10 text-yak-orange border-l-2 border-yak-orange font-medium' : 'text-yak-slate hover:bg-yak-cream' }}"
                           wire:navigate>
                            <flux:icon.clipboard-document-list class="size-5" />
                            {{ __('Tasks') }}
                        </a>
                        <a href="{{ route('costs') }}"
                           class="flex items-center gap-3 px-3 py-2.5 text-sm rounded-btn transition-colors {{ request()->routeIs('costs') ? 'bg-yak-orange/10 text-yak-orange border-l-2 border-yak-orange font-medium' : 'text-yak-slate hover:bg-yak-cream' }}"
                           wire:navigate>
                            <flux:icon.currency-dollar class="size-5" />
                            {{ __('Costs') }}
                        </a>
                        <a href="{{ route('repos') }}"
                           class="flex items-center gap-3 px-3 py-2.5 text-sm rounded-btn transition-colors {{ request()->routeIs('repos*') ? 'bg-yak-orange/10 text-yak-orange border-l-2 border-yak-orange font-medium' : 'text-yak-slate hover:bg-yak-cream' }}"
                           wire:navigate>
                            <flux:icon.code-bracket class="size-5" />
                            {{ __('Repositories') }}
                        </a>
                        <a href="{{ route('dashboard') }}"
                           class="flex items-center gap-3 px-3 py-2.5 text-sm rounded-btn transition-colors text-yak-slate hover:bg-yak-cream"
                           wire:navigate>
                            <flux:icon.heart class="size-5" />
                            {{ __('Health') }}
                        </a>
                    </nav>

                    {{-- User Menu --}}
                    <div class="px-3 py-4 border-t border-yak-tan/40">
                        <flux:dropdown position="top" align="start">
                            <button class="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-btn hover:bg-yak-cream transition-colors">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                    size="sm"
                                />
                                <span class="truncate text-yak-slate">{{ auth()->user()->name }}</span>
                            </button>

                            <flux:menu>
                                <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                                    {{ __('Settings') }}
                                </flux:menu.item>

                                <flux:menu.separator />

                                <form method="POST" action="{{ route('logout') }}" class="w-full">
                                    @csrf
                                    <flux:menu.item
                                        as="button"
                                        type="submit"
                                        icon="arrow-right-start-on-rectangle"
                                        class="w-full cursor-pointer"
                                        data-test="logout-button"
                                    >
                                        {{ __('Log out') }}
                                    </flux:menu.item>
                                </form>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                </div>
            </aside>

            {{-- Mobile Header --}}
            <div class="lg:hidden fixed top-0 inset-x-0 z-50 bg-yak-cream-dark border-b border-yak-tan/40 px-4 py-3 flex items-center justify-between">
                <a href="{{ route('dashboard') }}" wire:navigate>
                    <span class="font-serif text-2xl text-yak-slate tracking-tight">
                        Y<span class="italic text-yak-orange">a</span>k
                    </span>
                </a>

                <flux:dropdown position="bottom" align="end">
                    <flux:profile
                        :initials="auth()->user()->initials()"
                        icon-trailing="chevron-down"
                    />

                    <flux:menu>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>

                        <flux:menu.separator />

                        <form method="POST" action="{{ route('logout') }}" class="w-full">
                            @csrf
                            <flux:menu.item
                                as="button"
                                type="submit"
                                icon="arrow-right-start-on-rectangle"
                                class="w-full cursor-pointer"
                                data-test="logout-button"
                            >
                                {{ __('Log out') }}
                            </flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
            </div>

            {{-- Main Content --}}
            <main class="flex-1 lg:ml-60 pt-16 lg:pt-0">
                <div class="p-6 lg:p-8 relative z-10">
                    {{ $slot }}
                </div>
            </main>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
