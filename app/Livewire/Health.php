<?php

namespace App\Livewire;

use App\Services\HealthCheckService;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Health')]
class Health extends Component
{
    /**
     * @return list<array{name: string, healthy: bool, detail: string, checked_at: Carbon}>
     */
    #[Computed]
    public function checks(): array
    {
        return app(HealthCheckService::class)->runAll();
    }

    #[Computed]
    public function allHealthy(): bool
    {
        foreach ($this->checks() as $check) {
            if (! $check['healthy']) {
                return false;
            }
        }

        return true;
    }
}
