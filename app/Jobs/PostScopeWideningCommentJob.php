<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService;
use App\Enums\CoOwnerRequestStatus;
use App\Http\Resources\RiskProfilePageData;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\RiskProfileVerdict;
use App\Services\TaskLogger;
use App\Support\CodeOwners;
use App\Support\MarkdownText;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tells the reviewers of an approved pull request when a follow-up push
 * reaches held areas the approval did not cover. Each area is flagged once.
 */
class PostScopeWideningCommentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 300;

    public function __construct(
        public readonly YakTask $task,
        public readonly int $prNumber,
        public readonly string $headSha,
    ) {
        $this->onQueue('default');
    }

    public function handle(AppService $github, RiskProfileVerdict $verdict): void
    {
        $request = CoOwnerRequest::where('root_task_id', $this->task->rootTask()->id)
            ->where('status', CoOwnerRequestStatus::Approved)
            ->latest('id')
            ->first();
        $repository = Repository::where('slug', $this->task->repo)->first();

        if ($request === null || $repository === null) {
            return;
        }

        $settings = $repository->settings();
        $profile = RiskProfilePageData::effectiveProfile($repository, $settings);

        if ($profile === null) {
            return;
        }

        try {
            $changed = $github->listPullRequestFilesOrFail((int) config('yak.channels.github.installation_id'), $repository->github_full_name, $this->prNumber);
            $areas = array_values((array) ($profile['areas'] ?? []));
            $result = $verdict->evaluate(array_values($changed), $areas, $this->codeOwners($repository, $settings->snapshot->commitSha), 300, '');

            $covered = [...array_column($request->matched_areas, 'name'), ...$request->notified_areas];
            foreach ($result['heldAreas'] as $area) {
                if (! in_array($area['name'], $covered, true)) {
                    TaskLogger::info($this->task, "Follow-up touches {$area['name']} ({$area['risk']})");
                }
            }

            DB::transaction(function () use ($github, $request, $repository, $result): void {
                $locked = CoOwnerRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
                $covered = [...array_column($locked->matched_areas, 'name'), ...$locked->notified_areas];
                $newAreas = array_values(array_filter($result['heldAreas'], fn (array $area): bool => ! in_array($area['name'], $covered, true)));

                if ($newAreas === []) {
                    return;
                }

                $names = array_column($newAreas, 'name');
                $github->commentOnPullRequestOrFail(
                    (int) config('yak.channels.github.installation_id'),
                    $repository->github_full_name,
                    $this->prNumber,
                    $this->body($locked, $result, $newAreas),
                );

                $locked->update(['notified_areas' => [...$locked->notified_areas, ...$names]]);
            });
        } catch (Throwable $exception) {
            $detail = $exception instanceof RequestException
                ? $exception->response->status() . ' ' . ($exception->response->json('message') ?? $exception->response->reason())
                : $exception->getMessage();
            $retry = $this->attempts() < $this->tries ? " Retrying in 5m ({$this->attempts()} of {$this->tries})." : '';
            TaskLogger::warning($this->task, "Could not post the scope-widening comment on PR #{$this->prNumber}: {$detail}. The PR is unchanged. Reviewers are not notified of the new files.{$retry}");

            throw $exception;
        }
    }

    private function codeOwners(Repository $repository, ?string $sha): CodeOwners
    {
        if ($sha === null) {
            return CodeOwners::empty();
        }

        try {
            return RiskProfilePageData::codeOwners($repository, $sha);
        } catch (Throwable $exception) {
            report($exception);

            return CodeOwners::empty();
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<array{index: int, name: string, risk: string}>  $newAreas
     */
    private function body(CoOwnerRequest $request, array $result, array $newAreas): string
    {
        $login = (string) ($request->decided_by_login ?? $request->decidedBy?->github_login);
        $mention = preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})\z/', $login) === 1 ? " @{$login}" : '';
        $areaList = implode(', ', array_map(fn (array $area): string => MarkdownText::inline($area['name']), $newAreas));
        $indexes = array_column($newAreas, 'index');

        $lines = [];
        foreach ($result['files'] as $file) {
            if ($file['isHeld'] && in_array($file['area']['index'] ?? null, $indexes, true)) {
                $lines[] = '- ' . MarkdownText::code($file['path']) . ' ' . $file['area']['risk'];
            }
        }

        return "Heads up{$mention}: the latest follow-up also touches {$areaList}, which wasn't part of the original approval. Pushed in " . substr($this->headSha, 0, 7) . ". Please give these files a look before merging:\n\n"
            . implode("\n", $lines)
            . "\n\nThis note comes from the repository's risk profile, so it appears for any change that reaches these files. The rest of the PR is covered by your earlier approval.";
    }
}
