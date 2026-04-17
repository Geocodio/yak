<?php

namespace App\Livewire\Repos;

use App\Models\PrReview;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Repositories')]
class RepoList extends Component
{
    /**
     * @return Collection<int, Repository>
     */
    #[Computed]
    public function repositories(): Collection
    {
        $cutoff = now()->subDays(30);

        $reviewCounts = PrReview::query()
            ->where('submitted_at', '>=', $cutoff)
            ->selectRaw('repo, COUNT(*) as count')
            ->groupBy('repo')
            ->pluck('count', 'repo');

        $repos = Repository::query()
            ->withCount([
                'tasks',
                'tasks as tasks_recent_count' => function ($query) {
                    $query->where('created_at', '>=', now()->subDays(7));
                },
            ])
            ->orderByDesc('is_active')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        foreach ($repos as $repo) {
            $repo->pr_reviews_30d_count = (int) ($reviewCounts[$repo->slug] ?? 0);
        }

        return $repos;
    }

    public static function setupBadgeClasses(string $status): string
    {
        return match ($status) {
            'ready' => 'bg-[rgba(122,140,94,0.12)] text-[#7a8c5e]',
            'running' => 'bg-[rgba(143,179,196,0.12)] text-[#8fb3c4] animate-pulse',
            'pending' => 'bg-[rgba(107,143,163,0.12)] text-[#6b8fa3]',
            'failed' => 'bg-[rgba(184,84,80,0.12)] text-[#b85450]',
            default => 'bg-[rgba(200,184,154,0.12)] text-[#c8b89a]',
        };
    }

    public static function ciSystemLabel(string $ciSystem): string
    {
        return match ($ciSystem) {
            'github_actions' => 'GitHub Actions',
            'drone' => 'Drone',
            default => ucfirst($ciSystem),
        };
    }
}
