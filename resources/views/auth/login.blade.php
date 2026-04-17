<x-layouts::auth.simple :title="__('Sign In')">
    <div class="mb-1.5">
        <h1 class="font-serif text-[42px] leading-none tracking-tight text-yak-slate">
            Y<span class="italic text-yak-orange">a</span>k
        </h1>
    </div>

    <p class="mb-8 text-sm font-normal uppercase tracking-[0.08em] text-yak-blue">
        Papercuts, handled.
    </p>

    <div class="mb-7 flex items-center gap-4">
        <div class="h-px flex-1 bg-gradient-to-r from-transparent via-yak-tan to-transparent"></div>
        <svg class="h-[18px] w-[18px] flex-shrink-0 text-yak-tan" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
        </svg>
        <div class="h-px flex-1 bg-gradient-to-r from-transparent via-yak-tan to-transparent"></div>
    </div>

    <a href="{{ route('auth.google') }}" class="group relative flex w-full items-center justify-center gap-3 overflow-hidden rounded-btn bg-yak-slate dark:bg-[#2b3440] px-6 py-3.5 text-[15px] font-medium tracking-[0.02em] text-white transition-all duration-300 hover:-translate-y-0.5 hover:shadow-elevation-2">
        <span class="absolute inset-0 rounded-btn bg-gradient-to-br from-yak-blue via-yak-green to-yak-orange opacity-0 transition-opacity duration-400 group-hover:opacity-100"></span>
        <svg class="relative z-10 h-5 w-5 flex-shrink-0" viewBox="0 0 24 24">
            <path fill="#fff" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/>
            <path fill="#fff" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
            <path fill="#fff" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
            <path fill="#fff" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
        </svg>
        <span class="relative z-10">Continue with Google</span>
    </a>

    @if ($errors->any())
        <p class="mt-4 text-xs text-yak-danger">
            {{ $errors->first() }}
        </p>
    @endif

    <p class="mt-6 text-xs text-yak-blue">
        Team access only
    </p>
</x-layouts::auth.simple>
