@php
    use App\Services\HealthCheck\HealthStatus;
    $result = $this->result();
    $status = $result->status;

    $dotClass = match ($status) {
        HealthStatus::Ok => 'bg-yak-green',
        HealthStatus::Warn => 'bg-amber-500',
        HealthStatus::Error => 'bg-yak-danger',
        HealthStatus::NotConnected => 'bg-yak-tan',
    };
@endphp

<div class="flex items-center px-8 py-5 gap-4 border-b border-yak-tan/40 last:border-b-0 hover:bg-yak-cream/35 transition-colors">
    <div class="w-3 h-3 rounded-full shrink-0 {{ $dotClass }}"></div>

    <div class="flex-1 min-w-0">
        <div class="text-base font-medium text-yak-slate mb-0.5">{{ $this->name() }}</div>
        <div class="text-sm text-yak-blue">{{ $result->detail }}</div>
    </div>

    @if ($result->action)
        <flux:button
            size="sm"
            variant="primary"
            icon="link"
            href="{{ $result->action->url }}"
            class="shrink-0"
        >
            {{ $result->action->label }}
        </flux:button>
    @endif

    @if ($this->docsAnchor())
        <a
            href="{{ \App\Support\Docs::url($this->docsAnchor()) }}"
            target="_blank"
            rel="noopener noreferrer"
            class="text-yak-tan hover:text-yak-slate shrink-0 transition-colors"
            title="Open docs for this check"
            aria-label="Docs for {{ $this->name() }}"
        >
            <flux:icon.question-mark-circle variant="micro" />
        </a>
    @endif

    <button
        type="button"
        wire:click="refresh"
        wire:loading.class="animate-spin"
        wire:target="refresh"
        class="text-yak-tan hover:text-yak-slate shrink-0 transition-colors"
        title="Refresh this check"
        aria-label="Refresh {{ $this->name() }}"
    >
        <flux:icon.arrow-path variant="micro" />
    </button>
</div>
