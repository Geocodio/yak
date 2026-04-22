<?php

namespace App\Livewire;

use App\Actions\EnqueuePrReview;
use App\Channels\GitHub\AppService as GitHubAppService;
use App\Enums\TaskMode;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\YakTask;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('PR Review')]
class PrReviewForPr extends Component
{
    public string $repoSlug = '';

    public int $prNumber = 0;

    public function mount(string $repoSlug, int $prNumber): void
    {
        $this->repoSlug = $repoSlug;
        $this->prNumber = $prNumber;
    }

    /**
     * @return Collection<int, PrReview>
     */
    #[Computed]
    public function reviews(): Collection
    {
        return PrReview::query()
            ->where('repo', $this->repoSlug)
            ->where('pr_number', $this->prNumber)
            ->with(['task', 'comments'])
            ->orderByDesc('submitted_at')
            ->get();
    }

    public function rerunReview(): void
    {
        $prUrl = "https://github.com/{$this->repoSlug}/pull/{$this->prNumber}";

        $existing = YakTask::query()
            ->where('mode', TaskMode::Review)
            ->where('external_id', $prUrl)
            ->whereIn('status', ['pending', 'running'])
            ->exists();

        if ($existing) {
            Flux::toast('A review is already queued for this PR.', variant: 'warning');

            return;
        }

        $installationId = (int) config('yak.channels.github.installation_id');
        $prPayload = app(GitHubAppService::class)->getPullRequest($installationId, $this->repoSlug, $this->prNumber);

        if (! isset($prPayload['head']['sha'])) {
            Flux::toast('Failed to fetch PR from GitHub.', variant: 'danger');

            return;
        }

        $repo = Repository::where('slug', $this->repoSlug)->firstOrFail();

        app(EnqueuePrReview::class)->dispatch($repo, $prPayload, 'full');

        Flux::toast('Re-running review for this PR.');
    }

    public function render(): View
    {
        return view('livewire.pr-review-for-pr', [
            'reviews' => $this->reviews(),
        ]);
    }
}
