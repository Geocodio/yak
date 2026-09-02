<?php

namespace App\Http\Controllers;

use App\DataTransferObjects\BundledSkill;
use App\DataTransferObjects\InstalledPlugin;
use App\DataTransferObjects\Marketplace;
use App\DataTransferObjects\MarketplacePlugin;
use App\Exceptions\ClaudeCliException;
use App\Http\Requests\Skills\InstallSkillRequest;
use App\Services\MarketplaceReader;
use App\Services\SkillManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class SkillController extends Controller
{
    private const AVAILABLE_LIMIT = 60;

    public function __construct(
        private readonly SkillManager $skills,
        private readonly MarketplaceReader $marketplaceReader,
    ) {}

    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $filter = $request->string('filter', 'all')->toString();

        return Inertia::render('Skills/Index', [
            'installed' => fn () => $this->installedData($search),
            'bundled' => fn () => $this->bundledData($search),
            'available' => fn () => $this->availableData($search),
            'availableTotal' => fn () => $this->availableItems($search)->count(),
            'marketplaces' => fn () => $this->marketplacesData(),
            'filters' => [
                'search' => $search,
                'filter' => $filter,
            ],
        ]);
    }

    public function store(InstallSkillRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        return $this->runSafely(function () use ($validated) {
            if (! empty($validated['url'])) {
                $this->skills->installFromUrl($validated['url']);

                return 'Plugin installed.';
            }

            $name = (string) $validated['name'];
            $marketplace = $validated['marketplace'] ?? null;

            $this->skills->install($name, $marketplace !== '' ? $marketplace : null);

            return "Installed {$name}.";
        });
    }

    public function update(Request $request, string $name): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $validated['enabled'];

        return $this->runSafely(function () use ($name, $enabled) {
            $enabled ? $this->skills->enable($name) : $this->skills->disable($name);

            return $enabled ? "Enabled {$name}." : "Disabled {$name}.";
        });
    }

    public function destroy(string $name): RedirectResponse
    {
        return $this->runSafely(function () use ($name) {
            $this->skills->uninstall($name);

            return "Uninstalled {$name}.";
        });
    }

    public function upgrade(string $name): RedirectResponse
    {
        return $this->runSafely(function () use ($name) {
            $this->skills->update($name);

            return "Updated {$name}.";
        });
    }

    /**
     * @return array<int, array{key: string, name: string, marketplace: string, version: string, enabled: bool, installedAgo: string, lastUpdatedAgo: ?string}>
     */
    private function installedData(string $search): array
    {
        return $this->filterBySearch($this->skills->listInstalled(), fn (InstalledPlugin $p) => $p->name, $search)
            ->map(fn (InstalledPlugin $p) => [
                'key' => $p->key(),
                'name' => $p->name,
                'marketplace' => $p->marketplace,
                'version' => $p->version !== '' ? mb_substr($p->version, 0, 7) : '',
                'enabled' => $p->enabled,
                'installedAgo' => $p->installedAt->diffForHumans(),
                'lastUpdatedAgo' => $p->lastUpdated?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * @return array<int, array{name: string, description: string}>
     */
    private function bundledData(string $search): array
    {
        return $this->filterBySearch($this->skills->listBundledSkills(), fn (BundledSkill $s) => $s->name . ' ' . $s->description, $search)
            ->map(fn (BundledSkill $s) => [
                'name' => $s->name,
                'description' => $s->description,
            ])
            ->all();
    }

    /**
     * @return array<int, array{key: string, name: string, description: string, marketplace: string, category: ?string, link: ?string}>
     */
    private function availableData(string $search): array
    {
        return $this->availableItems($search)
            ->take(self::AVAILABLE_LIMIT)
            ->map(fn (MarketplacePlugin $p) => [
                'key' => $p->key(),
                'name' => $p->name,
                'description' => $p->description,
                'marketplace' => $p->marketplace,
                'category' => $p->category,
                'link' => $p->link(),
            ])
            ->all();
    }

    /**
     * @return Collection<int, MarketplacePlugin>
     */
    private function availableItems(string $search): Collection
    {
        $installedKeys = $this->skills->listInstalled()->map->key()->all();

        $available = $this->marketplaceReader->listAll()
            ->reject(fn (MarketplacePlugin $p) => in_array($p->key(), $installedKeys, true))
            ->values();

        return $this->filterBySearch($available, fn (MarketplacePlugin $p) => $p->name . ' ' . $p->description, $search);
    }

    /**
     * @return array<int, array{name: string, source: string, lastUpdatedAgo: ?string}>
     */
    private function marketplacesData(): array
    {
        return $this->skills->listMarketplaces()
            ->map(fn (Marketplace $m) => [
                'name' => $m->name,
                'source' => $m->source,
                'lastUpdatedAgo' => $m->lastUpdated?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * @template TItem
     *
     * @param  Collection<int, TItem>  $items
     * @param  \Closure(TItem): string  $haystackFn
     * @return Collection<int, TItem>
     */
    private function filterBySearch(Collection $items, \Closure $haystackFn, string $search): Collection
    {
        if ($search === '') {
            return $items;
        }

        $needle = mb_strtolower($search);

        return $items->filter(fn ($item) => str_contains(mb_strtolower($haystackFn($item)), $needle))->values();
    }

    private function runSafely(\Closure $action): RedirectResponse
    {
        try {
            $message = $action();
        } catch (ClaudeCliException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $message);
    }
}
