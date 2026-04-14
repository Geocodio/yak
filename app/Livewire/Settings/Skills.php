<?php

namespace App\Livewire\Settings;

use App\DataTransferObjects\BundledSkill;
use App\DataTransferObjects\InstalledPlugin;
use App\DataTransferObjects\Marketplace;
use App\DataTransferObjects\MarketplacePlugin;
use App\Exceptions\ClaudeCliException;
use App\Services\MarketplaceReader;
use App\Services\SkillManager;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('Skills')]
class Skills extends Component
{
    public string $search = '';

    public string $filter = 'all';

    public bool $showInstallFromUrl = false;

    #[Validate('required|string|min:3')]
    public string $installUrl = '';

    #[Validate('nullable|string|min:3')]
    public string $newMarketplace = '';

    /**
     * @return Collection<int, InstalledPlugin>
     */
    #[Computed]
    public function installed(): Collection
    {
        return $this->filterBySearch(
            app(SkillManager::class)->listInstalled(),
            fn ($p) => $p->name,
        );
    }

    /**
     * @return Collection<int, BundledSkill>
     */
    #[Computed]
    public function bundled(): Collection
    {
        return $this->filterBySearch(
            app(SkillManager::class)->listBundledSkills(),
            fn ($s) => $s->name . ' ' . $s->description,
        );
    }

    /**
     * @return Collection<int, MarketplacePlugin>
     */
    #[Computed]
    public function available(): Collection
    {
        $installedKeys = app(SkillManager::class)->listInstalled()->map->key()->all();

        $available = app(MarketplaceReader::class)->listAll()
            ->reject(fn ($p) => in_array($p->key(), $installedKeys, true))
            ->values();

        return $this->filterBySearch($available, fn ($p) => $p->name . ' ' . $p->description);
    }

    /**
     * @return Collection<int, Marketplace>
     */
    #[Computed]
    public function marketplaces(): Collection
    {
        return app(SkillManager::class)->listMarketplaces();
    }

    /**
     * @template TItem
     *
     * @param  Collection<int, TItem>  $items
     * @param  \Closure(TItem): string  $haystackFn
     * @return Collection<int, TItem>
     */
    private function filterBySearch(Collection $items, \Closure $haystackFn): Collection
    {
        if ($this->search === '') {
            return $items;
        }

        $needle = mb_strtolower($this->search);

        return $items->filter(fn ($item) => str_contains(mb_strtolower($haystackFn($item)), $needle))->values();
    }

    public function install(string $name, ?string $marketplace = null): void
    {
        $this->runSafely(
            fn () => app(SkillManager::class)->install($name, $marketplace),
            "Installed {$name}.",
        );
    }

    public function installFromUrl(): void
    {
        $this->validateOnly('installUrl');

        $this->runSafely(
            fn () => app(SkillManager::class)->installFromUrl($this->installUrl),
            'Plugin installed.',
            onSuccess: function () {
                $this->installUrl = '';
                $this->showInstallFromUrl = false;
            },
        );
    }

    public function uninstall(string $name): void
    {
        $this->runSafely(
            fn () => app(SkillManager::class)->uninstall($name),
            "Uninstalled {$name}.",
        );
    }

    public function toggle(string $name, bool $enable): void
    {
        $this->runSafely(
            fn () => $enable
                ? app(SkillManager::class)->enable($name)
                : app(SkillManager::class)->disable($name),
            $enable ? "Enabled {$name}." : "Disabled {$name}.",
        );
    }

    public function updatePlugin(string $name): void
    {
        $this->runSafely(
            fn () => app(SkillManager::class)->update($name),
            "Updated {$name}.",
        );
    }

    public function addMarketplace(): void
    {
        $this->validate(['newMarketplace' => ['required', 'string', 'min:3']]);

        $this->runSafely(
            fn () => app(SkillManager::class)->addMarketplace($this->newMarketplace),
            'Marketplace added.',
            onSuccess: fn () => $this->newMarketplace = '',
        );
    }

    public function removeMarketplace(string $name): void
    {
        $this->runSafely(
            fn () => app(SkillManager::class)->removeMarketplace($name),
            "Removed marketplace {$name}.",
        );
    }

    public function refreshMarketplaces(): void
    {
        $this->runSafely(
            fn () => app(SkillManager::class)->refreshMarketplaces(),
            'Marketplaces refreshed.',
        );
    }

    private function runSafely(\Closure $action, string $successMessage, ?\Closure $onSuccess = null): void
    {
        try {
            $action();

            if ($onSuccess !== null) {
                $onSuccess();
            }

            unset($this->installed, $this->bundled, $this->available, $this->marketplaces);

            Flux::toast(variant: 'success', text: $successMessage);
        } catch (ClaudeCliException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function render(): View
    {
        return view('livewire.settings.skills');
    }
}
