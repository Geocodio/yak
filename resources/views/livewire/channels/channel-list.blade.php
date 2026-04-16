<div>
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-yak-slate">Channels</h1>
            <p class="mt-1 text-sm text-yak-blue max-w-2xl">Each channel connects Yak to a service users already live in. Everything except GitHub is optional — enable whichever match how your team works.</p>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        @foreach($this->channels as $channel)
            <div
                class="rounded-[20px] border border-yak-tan/40 bg-white p-5 shadow-yak"
                data-testid="channel-card-{{ $channel['slug'] }}"
            >
                <div class="flex items-start gap-4">
                    <div class="shrink-0 rounded-lg bg-yak-cream p-2.5">
                        <flux:icon :name="$channel['icon']" class="!size-5 text-yak-blue" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-semibold text-yak-slate">{{ $channel['name'] }}</h2>

                            @if($channel['required'])
                                <span class="inline-block rounded-md bg-yak-orange/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-yak-orange">Required</span>
                            @elseif($channel['enabled'])
                                <span class="inline-block rounded-md bg-yak-green/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-yak-green">Connected</span>
                            @else
                                <span class="inline-block rounded-md bg-yak-tan/20 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-yak-tan">Not connected</span>
                            @endif

                            @if($channel['enabled'] && $channel['status'])
                                @php
                                    $dotClass = match ($channel['status']->status) {
                                        \App\Services\HealthCheck\HealthStatus::Ok => 'bg-yak-green',
                                        \App\Services\HealthCheck\HealthStatus::Warn => 'bg-amber-500',
                                        \App\Services\HealthCheck\HealthStatus::Error => 'bg-yak-danger',
                                        \App\Services\HealthCheck\HealthStatus::NotConnected => 'bg-yak-tan',
                                    };
                                @endphp
                                <span class="ml-1 flex items-center gap-1.5 text-xs text-yak-blue">
                                    <span class="size-2 rounded-full {{ $dotClass }}"></span>
                                    {{ ucfirst($channel['status']->status->value) }}
                                </span>
                            @endif
                        </div>
                        <p class="mt-0.5 text-xs text-yak-blue">{{ $channel['role'] }}</p>
                    </div>
                </div>

                <p class="mt-3 text-sm leading-relaxed text-yak-slate">{{ $channel['description'] }}</p>

                @if($channel['enabled'] && $channel['status'] && $channel['status']->detail)
                    <p class="mt-2 text-xs text-yak-blue">{{ $channel['status']->detail }}</p>
                @endif

                <div class="mt-4 rounded-lg bg-yak-cream-dark p-3">
                    <div class="mb-1 text-[10px] font-semibold uppercase tracking-wider text-yak-tan">Vault keys</div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($channel['vault_keys'] as $key)
                            <code class="rounded bg-white px-1.5 py-0.5 font-mono text-[11px] text-yak-slate">{{ $key }}</code>
                        @endforeach
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between text-sm">
                    <a
                        href="{{ $channel['docs_url'] }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 font-medium text-yak-orange hover:text-yak-orange-warm transition-colors"
                    >
                        Setup guide
                        <flux:icon.arrow-top-right-on-square class="!size-3.5 opacity-70" />
                    </a>
                    @if($channel['enabled'])
                        <a href="{{ route('health') }}" wire:navigate class="text-xs text-yak-blue hover:text-yak-slate transition-colors">See on Health →</a>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
