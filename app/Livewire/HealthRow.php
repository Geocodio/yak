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

    public function placeholder(): string
    {
        $name = app(Registry::class)->nameFor($this->checkId);

        return view('livewire.partials.health-row-skeleton', ['name' => $name])->render();
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
