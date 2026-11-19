<?php

namespace App\Http\Controllers\PrReviews;

use App\Actions\EnqueuePrReview;
use App\Channels\GitHub\AppService as GitHubAppService;
use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Resources\PrReviewRunData;
use App\Http\Resources\ThreadRunLogData;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use App\Services\Tasks\TaskActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PrReviewForPrController extends Controller
{
    public function show(string $repoSlug, int $prNumber): Response
    {
        $built = null;
        $page = function () use (&$built, $repoSlug, $prNumber): array {
            return $built ??= PrReviewRunData::build(PrReviewRunData::tasks($repoSlug, $prNumber), $repoSlug, $prNumber);
        };

        return Inertia::render('PrReviews/ForPr', [
            'pr' => fn () => $this->prData($repoSlug, $prNumber),
            'runs' => fn () => $page()['runs'],
            'pullRequest' => fn () => $page()['panel'],
            'description' => fn () => $page()['description'],
            'modelLabels' => fn () => app(ClaudeModelCatalog::class)->labels(),
        ]);
    }

    public function runLog(Request $request, string $repoSlug, int $prNumber, YakTask $task): JsonResponse
    {
        abort_unless(PrReviewRunData::belongsToPullRequest($task, $repoSlug, $prNumber), 404);

        $search = $request->query('search');

        return response()->json(ThreadRunLogData::forTask(
            $task,
            max(0, $request->integer('cursor')),
            is_string($search) && trim($search) !== '' ? mb_substr(trim($search), 0, 200) : null,
        ));
    }

    public function rerunRun(string $repoSlug, int $prNumber, YakTask $task, TaskActions $actions): RedirectResponse
    {
        abort_unless(PrReviewRunData::belongsToPullRequest($task, $repoSlug, $prNumber), 404);

        $back = redirect()->route('pr-reviews.for-pr', [$repoSlug, $prNumber]);

        try {
            $outcome = $actions->rerunReview($task);
        } catch (ThreadActionRefused|HttpException $refused) {
            if ($refused instanceof HttpException && $refused->getStatusCode() !== 503) {
                throw $refused;
            }

            return $back->with('error', $refused->getMessage());
        }

        return $outcome->sentence === '' ? $back : $back->with('success', $outcome->sentence);
    }

    public function rerun(string $repoSlug, int $prNumber, GitHubAppService $github): RedirectResponse
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $prPayload = $github->getPullRequest($installationId, $repoSlug, $prNumber);

        if (! isset($prPayload['head']['sha'])) {
            return redirect()->route('pr-reviews.for-pr', [$repoSlug, $prNumber])
                ->with('error', 'Failed to fetch PR from GitHub.');
        }

        $repository = Repository::where('slug', $repoSlug)->first();

        if ($repository === null) {
            return redirect()->route('pr-reviews.for-pr', [$repoSlug, $prNumber])
                ->with('error', 'Repository not found.');
        }

        $task = app(EnqueuePrReview::class)->dispatch($repository, $prPayload, 'full');

        if ($task === null) {
            return redirect()->route('pr-reviews.for-pr', [$repoSlug, $prNumber])
                ->with('error', 'A review is already queued for this PR.');
        }

        return redirect()->route('pr-reviews.for-pr', [$repoSlug, $prNumber])
            ->with('success', 'Re-running review for this PR.');
    }

    /**
     * @return array{repoSlug: string, number: int, title: string, url: string}
     */
    private function prData(string $repoSlug, int $prNumber): array
    {
        $latest = PrReview::query()
            ->where('repo', $repoSlug)
            ->where('pr_number', $prNumber)
            ->with('task')
            ->orderByDesc('submitted_at')
            ->first();

        $title = $this->titleFor($latest, $prNumber);

        return [
            'repoSlug' => $repoSlug,
            'number' => $prNumber,
            'title' => $title,
            'url' => "https://github.com/{$repoSlug}/pull/{$prNumber}",
        ];
    }

    private function titleFor(?PrReview $review, int $prNumber): string
    {
        $description = (string) ($review?->task->description ?? '');
        $prefix = "Review PR #{$prNumber}: ";

        if ($description !== '' && str_starts_with($description, $prefix)) {
            return substr($description, strlen($prefix));
        }

        return $description !== '' ? $description : "PR #{$prNumber}";
    }
}
