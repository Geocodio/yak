<div wire:poll.60s>
    <div class="flex items-center justify-between mb-8">
        <h1 class="text-2xl font-semibold text-yak-slate">Health</h1>
        <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="refresh">
            Refresh
        </flux:button>
    </div>

    {{-- Overall Status Card --}}
    <div class="bg-white/75 backdrop-blur-[40px] backdrop-saturate-[1.4] border border-white/60 rounded-[28px] shadow-yak p-7 mb-8">
        <div class="flex flex-col gap-2">
            <div class="flex items-center gap-3">
                <div class="w-4 h-4 rounded-full shrink-0 shadow-[0_0_0_4px_rgba(122,140,94,0.15)] {{ $this->allHealthy() ? 'bg-yak-green' : 'bg-yak-danger' }}"></div>
                <span class="text-lg font-medium {{ $this->allHealthy() ? 'text-yak-green' : 'text-yak-danger' }}">
                    {{ $this->allHealthy() ? 'All Systems Operational' : 'Issues Detected' }}
                </span>
            </div>
            @if (count($this->checks()) > 0)
                <div class="text-xs text-yak-blue pl-7">
                    Last checked {{ $this->checks()[0]['checked_at']->diffForHumans() }}
                </div>
            @endif
        </div>
    </div>

    {{-- Health Check Rows --}}
    <div class="bg-white border border-yak-tan/40 rounded-[28px] shadow-yak overflow-hidden">
        @foreach ($this->checks() as $check)
            <div class="flex items-center px-8 py-5 gap-4 border-b border-yak-tan/40 last:border-b-0 hover:bg-yak-cream/35 transition-colors">
                <div class="w-3 h-3 rounded-full shrink-0 {{ $check['healthy'] ? 'bg-yak-green' : 'bg-yak-danger' }}"></div>
                <div class="flex-1 min-w-0">
                    <div class="text-base font-medium text-yak-slate mb-0.5">{{ $check['name'] }}</div>
                    <div class="text-sm text-yak-blue">{{ $check['detail'] }}</div>
                </div>
                <div class="text-xs text-yak-tan shrink-0 whitespace-nowrap">
                    Checked {{ $check['checked_at']->diffForHumans() }}
                </div>
            </div>
        @endforeach
    </div>
</div>
