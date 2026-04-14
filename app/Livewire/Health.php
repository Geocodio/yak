<?php

namespace App\Livewire;

use App\Services\HealthCheck\HealthSection;
use App\Services\HealthCheck\Registry;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Health')]
class Health extends Component
{
    /**
     * @return list<string>
     */
    #[Computed]
    public function systemCheckIds(): array
    {
        return array_map(
            fn ($check): string => $check->id(),
            app(Registry::class)->forSection(HealthSection::System),
        );
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function channelCheckIds(): array
    {
        return array_map(
            fn ($check): string => $check->id(),
            app(Registry::class)->forSection(HealthSection::Channels),
        );
    }

    public function refreshAll(): void
    {
        $this->dispatch('health-refresh');
    }
}
