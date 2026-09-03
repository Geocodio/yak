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
    private const AVAILABLE_PER_PAGE = 24;

    public function __construct(
        private readonly SkillManager $skills,
        private readonly MarketplaceReader $marketplaceReader,
    ) {}

    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $filter = $request->string('filter', 'all')->toString();
        $category = $request->string('category')->toString();
        $page = max(1, $request->integer('page', 1));

        return Inertia::render('Skills/Index', [
            'installed' => fn () => $this->installedData($search),
            'bundled' => fn () => $this->bundledData($search),
            'available' => fn () => $this->availableData($search, $category, $page),
            'categories' => fn () => $this->categoriesData($search),
            'recommended' => fn () => $this->recommendedData($search, $filter),
            'marketplaces' => fn () => $this->marketplacesData(),
            'filters' => [
                'search' => $search,
                'filter' => $filter,
                'category' => $category,
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
            $result = $this->skills->update($name);
            $version = $result->version !== null ? " ({$result->version})" : '';

            return $result->updated
                ? "Updated {$name}{$version}."
                : "{$name} is already at the latest version{$version}.";
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
     * @return array{items: array<int, array{key: string, name: string, description: string, marketplace: string, category: ?string, link: ?string}>, page: int, lastPage: int, total: int, perPage: int}
     */
    private function availableData(string $search, string $category, int $page): array
    {
        $items = $this->sortedAvailableItems($search);

        if ($category !== '') {
            $items = $items->filter(fn (MarketplacePlugin $p) => $this->categoryValue($p) === $category)->values();
        }

        $total = $items->count();
        $lastPage = max(1, (int) ceil($total / self::AVAILABLE_PER_PAGE));
        $page = max(1, min($page, $lastPage));

        $pageItems = $items->slice(($page - 1) * self::AVAILABLE_PER_PAGE, self::AVAILABLE_PER_PAGE)->values();

        return [
            'items' => $pageItems->map(fn (MarketplacePlugin $p) => $this->toRow($p))->all(),
            'page' => $page,
            'lastPage' => $lastPage,
            'total' => $total,
            'perPage' => self::AVAILABLE_PER_PAGE,
        ];
    }

    /**
     * @return array<int, array{value: string, label: string, count: int}>
     */
    private function categoriesData(string $search): array
    {
        $counts = [];

        foreach ($this->availableItems($search) as $plugin) {
            $value = $this->categoryValue($plugin);
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        $otherCount = $counts['other'] ?? null;
        unset($counts['other']);

        $rows = [];

        foreach ($counts as $value => $count) {
            $rows[] = ['value' => $value, 'label' => ucfirst($value), 'count' => $count];
        }

        usort($rows, fn (array $a, array $b) => $b['count'] <=> $a['count'] ?: strcasecmp($a['label'], $b['label']));

        if ($otherCount !== null) {
            $rows[] = ['value' => 'other', 'label' => 'Other', 'count' => $otherCount];
        }

        return $rows;
    }

    /**
     * @return array<int, array{key: string, name: string, description: string, marketplace: string, category: ?string, link: ?string, recommendedReason: string}>
     */
    private function recommendedData(string $search, string $filter): array
    {
        if ($search !== '' || ! in_array($filter, ['all', 'available'], true)) {
            return [];
        }

        $installed = $this->skills->listInstalled();
        $installedKeys = $installed->map->key()->all();

        $available = $this->marketplaceReader->listAll()
            ->reject(fn (MarketplacePlugin $p) => in_array($p->key(), $installedKeys, true))
            ->values();

        /** @var array<int, string> $popularNames */
        $popularNames = config('yak.recommended_plugins', []);

        $popular = $available
            ->filter(fn (MarketplacePlugin $p) => in_array($p->name, $popularNames, true))
            ->sortBy(fn (MarketplacePlugin $p) => array_search($p->name, $popularNames, true))
            ->values();

        $popularKeys = $popular->map->key()->all();

        $installedCategories = $this->installedCategories($installed);

        $similar = $available
            ->reject(fn (MarketplacePlugin $p) => in_array($p->key(), $popularKeys, true))
            ->filter(fn (MarketplacePlugin $p) => $p->category !== null && in_array(mb_strtolower($p->category), $installedCategories, true))
            ->sortBy(fn (MarketplacePlugin $p) => mb_strtolower($p->name))
            ->values();

        return $popular->map(fn (MarketplacePlugin $p) => $this->toRow($p) + ['recommendedReason' => 'popular'])
            ->concat($similar->map(fn (MarketplacePlugin $p) => $this->toRow($p) + ['recommendedReason' => 'similar']))
            ->unique('key')
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, InstalledPlugin>  $installed
     * @return array<int, string>
     */
    private function installedCategories(Collection $installed): array
    {
        $installedKeys = $installed->map->key()->all();

        return $this->marketplaceReader->listAll()
            ->filter(fn (MarketplacePlugin $p) => $p->category !== null && in_array($p->key(), $installedKeys, true))
            ->map(fn (MarketplacePlugin $p) => mb_strtolower((string) $p->category))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{key: string, name: string, description: string, marketplace: string, category: ?string, link: ?string}
     */
    private function toRow(MarketplacePlugin $p): array
    {
        return [
            'key' => $p->key(),
            'name' => $p->name,
            'description' => $p->description,
            'marketplace' => $p->marketplace,
            'category' => $p->category,
            'link' => $p->link(),
        ];
    }

    private function categoryValue(MarketplacePlugin $p): string
    {
        return $p->category !== null ? mb_strtolower($p->category) : 'other';
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
     * @return Collection<int, MarketplacePlugin>
     */
    private function sortedAvailableItems(string $search): Collection
    {
        return $this->availableItems($search)
            ->sort(function (MarketplacePlugin $a, MarketplacePlugin $b) {
                $aCategory = $a->category !== null ? mb_strtolower($a->category) : "\u{10FFFF}";
                $bCategory = $b->category !== null ? mb_strtolower($b->category) : "\u{10FFFF}";

                return [$aCategory, mb_strtolower($a->name)] <=> [$bCategory, mb_strtolower($b->name)];
            })
            ->values();
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
