<?php

namespace App\Livewire;

use App\Services\HealthCheck\HealthResult;
use App\Services\HealthCheck\Registry;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

#[Lazy]
class HealthRow extends Component
{
    public string $checkId;

    private const CACHE_TTL_SECONDS = 60;

    /**
     * Maps a check ID to the most relevant docs anchor. Used to render a
     * "?" icon link next to each row so users can jump straight to the
     * section explaining how to configure or fix that dependency.
     *
     * @var array<string, string>
     */
    private const DOCS_ANCHOR_BY_CHECK = [
        'queue-worker' => 'troubleshooting',
        'last-task-completed' => 'troubleshooting',
        'incus-daemon' => 'architecture.sandbox',
        'sandbox-base-template' => 'architecture.sandbox',
        'claude-cli' => 'troubleshooting.cli',
        'claude-auth' => 'troubleshooting.cli',
        'repositories' => 'repositories',
        'webhook-signatures' => 'troubleshooting.webhooks',
        'slack' => 'channels.slack',
        'linear' => 'channels.linear',
        'sentry' => 'channels.sentry',
        'github' => 'channels.github',
        'drone' => 'channels.drone',
    ];

    public function placeholder(): string
    {
        $name = app(Registry::class)->nameFor($this->checkId);

        return view('livewire.partials.health-row-skeleton', ['name' => $name])->render();
    }

    public function docsAnchor(): ?string
    {
        return self::DOCS_ANCHOR_BY_CHECK[$this->checkId] ?? null;
    }

    #[Computed]
    public function result(): HealthResult
    {
        return Cache::remember(
            "health:check:{$this->checkId}",
            self::CACHE_TTL_SECONDS,
            fn () => app(Registry::class)->get($this->checkId)->run(),
        );
    }

    #[Computed]
    public function name(): string
    {
        return app(Registry::class)->nameFor($this->checkId);
    }

    public function refresh(): void
    {
        Cache::forget("health:check:{$this->checkId}");
        unset($this->result);
    }

    #[On('health-refresh')]
    public function handleRefresh(): void
    {
        $this->refresh();
    }
}
