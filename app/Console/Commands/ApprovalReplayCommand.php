<?php

namespace App\Console\Commands;

use App\Channels\GitHub\AppService;
use App\DataTransferObjects\ConfigFile;
use App\DataTransferObjects\ConfigSnapshot;
use App\DataTransferObjects\ParsedPriorFinding;
use App\DataTransferObjects\ParsedReview;
use App\DataTransferObjects\ReviewFinding;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\TelemetryEvent;
use App\Services\RepositoryConfig;
use App\Services\RepositoryConfigParser;
use App\Services\ReviewApprovalPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Runs recent stored reviews through the approval policy so levels and concern
 * lists can be compared before anything is enforced. Read-only: it writes no
 * rows, saves no settings and posts nothing to GitHub.
 *
 * The policy normally checks the live pull request, and a historical one is
 * usually closed or has moved on. The replay therefore answers those live
 * checks from the stored review: the pull request is open, not a draft, has
 * no auto-merge, comes from the same repository, sits at the reviewed commit
 * and has as many changed files as were replayed. Required status checks count
 * as present. The bot login, the diff and the classifier stay real.
 */
#[Signature('yak:approval-replay {slug : Repository slug} {--limit=100 : Latest distinct pull requests to replay} {--level= : DEFCON level for this run only} {--concerns= : Path to a local risk-profile.yml to use instead of the default branch}')]
#[Description('Replay recent stored reviews through the approval policy (read-only; live pull request state and required status checks are assumed to pass)')]
class ApprovalReplayCommand extends Command
{
    public function handle(AppService $github, ReviewApprovalPolicy $policy): int
    {
        $repository = Repository::where('slug', $this->argument('slug'))->first();
        if ($repository === null) {
            $this->components->error('Unknown repository.');

            return self::FAILURE;
        }

        $level = $this->option('level');
        if ($level !== null && (! ctype_digit((string) $level) || (int) $level < 1 || (int) $level > 5)) {
            $this->components->error('The level must be 1 to 5.');

            return self::FAILURE;
        }

        if ($this->option('concerns') !== null && ! $this->bindConcernsFile((string) $this->option('concerns'))) {
            return self::FAILURE;
        }

        $replayRepository = $this->replayRepository($repository, $level === null ? $repository->approvalLevel() : (int) $level);
        $installationId = (int) config('yak.channels.github.installation_id');

        $reviews = PrReview::query()
            ->where('repo', $repository->slug)
            ->where('review_scope', 'full')
            ->orderByDesc('id')
            ->get()
            ->unique('pr_number')
            ->take((int) $this->option('limit'));

        $rows = [];
        $candidates = [];
        $concernCounts = [];
        foreach ($reviews as $review) {
            $result = $this->replay($github, $policy, $replayRepository, $installationId, $review);
            $candidates[] = $result['candidate'];
            foreach ($result['concerns'] as $concern) {
                $concernCounts[$concern] = ($concernCounts[$concern] ?? 0) + 1;
            }
            $rows[] = [
                "#{$review->pr_number}", $result['title'], $result['candidate'], $result['changeKind'] ?? '-',
                $result['concerns'] === [] ? '-' : implode(', ', $result['concerns']), $result['reason'] ?? '-',
            ];
        }

        if ($rows === []) {
            $this->components->info('No full reviews to replay.');

            return self::SUCCESS;
        }

        $this->table(['PR', 'Title', 'Candidate', 'Change kind', 'Touched concerns', 'First reason'], $rows);
        $this->table(['Candidate', 'PRs'], collect($candidates)->countBy()->sortKeys()->map(fn (int $count, string $candidate): array => [$candidate, $count])->values()->all());
        arsort($concernCounts);
        $this->table(['Touched concern', 'PRs'], collect($concernCounts)->map(fn (int $count, string $concern): array => [$concern, $count])->values()->all());

        return self::SUCCESS;
    }

    /**
     * @return array{candidate: string, title: string, changeKind: ?string, concerns: list<string>, reason: ?string}
     */
    private function replay(AppService $github, ReviewApprovalPolicy $policy, Repository $repository, int $installationId, PrReview $review): array
    {
        $unavailable = fn (string $title, string $reason): array => ['candidate' => 'UNAVAILABLE', 'title' => $title, 'changeKind' => null, 'concerns' => [], 'reason' => $reason];

        $assessment = (array) $review->risk_assessment;
        $headSha = (string) ($assessment['head_sha'] ?? $review->commit_sha_reviewed);
        $baseSha = (string) ($assessment['base_sha'] ?? '');
        $event = TelemetryEvent::where('name', 'review.submitted')->where('yak_task_id', $review->yak_task_id)->latest('id')->first();
        if ($baseSha === '' || $event === null) {
            return $unavailable('-', 'Stored review is missing its base commit or telemetry.');
        }

        try {
            $pullRequest = $github->getPullRequestOrFail($installationId, $repository->github_full_name, $review->pr_number);
            $files = $github->compareFilesOrFail($installationId, $repository->github_full_name, $baseSha, $headSha);
        } catch (Throwable $exception) {
            return $unavailable('-', 'GitHub request failed.');
        }
        if ($pullRequest === null || $files === null) {
            return $unavailable('-', 'Pull request or commits no longer exist.');
        }

        $title = (string) ($pullRequest['title'] ?? '-');
        $baseRef = (string) ($pullRequest['base']['ref'] ?? '');
        $author = (string) ($pullRequest['user']['login'] ?? '');
        $syntheticPullRequest = [
            'state' => 'open', 'draft' => false, 'auto_merge' => null, 'user' => ['login' => $author],
            'head' => ['sha' => $headSha, 'repo' => ['full_name' => $repository->github_full_name]],
            'base' => ['ref' => $baseRef, 'repo' => ['full_name' => $repository->github_full_name]],
            'changed_files' => count($files),
        ];
        $this->app()->instance(AppService::class, $this->replayGitHub($github, $syntheticPullRequest));

        $metadata = ['pr_number' => $review->pr_number, 'pr_url' => $review->pr_url, 'author' => $author, 'head_sha' => $headSha,
            'base_sha' => $baseSha, 'base_ref' => $baseRef, 'review_scope' => 'full'];

        try {
            $decision = $policy->evaluate($repository, $this->parsedReview($review, (array) $event->properties), $metadata, $files);
        } catch (Throwable $exception) {
            return $unavailable($title, 'Policy evaluation failed.');
        } finally {
            $this->app()->instance(AppService::class, $github);
        }

        return [
            'candidate' => (string) $decision['candidate'], 'title' => $title,
            'changeKind' => $decision['classification']['change_kind'] ?? null,
            'concerns' => array_column($decision['classification']['touched_concerns'] ?? [], 'name'),
            'reason' => $decision['reasons'][0] ?? null,
        ];
    }

    /**
     * The verdict is stored with the review; finding severities and the
     * prior-finding count come from the review.submitted event. Statuses of
     * prior findings are not stored, so any prior finding counts as still open.
     *
     * @param  array<string, mixed>  $properties
     */
    private function parsedReview(PrReview $review, array $properties): ParsedReview
    {
        $findings = [];
        foreach ((array) ($properties['severities'] ?? []) as $severity => $count) {
            for ($index = 0; $index < (int) $count; $index++) {
                $findings[] = new ReviewFinding('unknown', 0, (string) $severity, 'unknown', '');
            }
        }
        $priorFindings = (int) ($properties['prior_findings'] ?? 0) > 0
            ? [new ParsedPriorFinding(0, ParsedPriorFinding::STATUS_STILL_OUTSTANDING, '')]
            : [];

        return new ParsedReview((string) $review->summary, (string) $review->verdict, '', $findings, $priorFindings);
    }

    /**
     * Answers the live pull request checks from the replayed review and treats
     * required status checks as present; everything else is the real service.
     *
     * @param  array<string, mixed>  $pullRequest
     */
    private function replayGitHub(AppService $github, array $pullRequest): AppService
    {
        return new class($github, $pullRequest) extends AppService
        {
            /** @param array<string, mixed> $pullRequest */
            public function __construct(private AppService $inner, private array $pullRequest) {}

            public function appBotLogin(): string
            {
                return $this->inner->appBotLogin();
            }

            public function getPullRequestOrFail(int $installationId, string $repoSlug, int $prNumber): ?array
            {
                return $this->pullRequest;
            }

            public function hasRequiredStatusChecks(int $installationId, string $repoSlug, string $branch): bool
            {
                return true;
            }
        };
    }

    /**
     * A copy of the repository, active with reviews enabled, in shadow mode at the replay level. The policy
     * refreshes the model from the database, so the copy's refresh is a no-op.
     */
    private function replayRepository(Repository $repository, int $level): Repository
    {
        $copy = new class extends Repository
        {
            protected $table = 'repositories';

            public int $replayLevel = 1;

            public function refresh(): static
            {
                return $this;
            }

            public function approvalLevel(): int
            {
                return $this->replayLevel;
            }

            public function approvalMode(): string
            {
                return $this->replayLevel === 1 ? 'off' : 'shadow';
            }
        };
        $copy->setRawAttributes($repository->getAttributes(), true);
        $copy->exists = true;
        $copy->is_active = true;
        $copy->pr_review_enabled = true;
        $copy->replayLevel = $level;

        return $copy;
    }

    private function bindConcernsFile(string $path): bool
    {
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            $this->components->error("Cannot read {$path}.");

            return false;
        }

        $result = (new RepositoryConfigParser)->parse('risk-profile.yml', $content);
        if ($result['errors'] !== [] || ($result['data']['version'] ?? null) !== 2) {
            $this->components->error('Not a valid risk-profile.yml version 2: ' . implode('; ', $result['errors']));

            return false;
        }

        $sha = str_repeat('0', 40);
        $snapshot = new ConfigSnapshot('read', $sha, CarbonImmutable::now(), null, [
            'risk-profile.yml' => new ConfigFile('risk-profile.yml', $result['data'], $content, $sha, null, null, null),
        ]);
        $this->app()->instance(RepositoryConfig::class, new class($snapshot) extends RepositoryConfig
        {
            public function __construct(private ConfigSnapshot $fixedSnapshot) {}

            public function snapshot(Repository $repository): ConfigSnapshot
            {
                return $this->fixedSnapshot;
            }
        });

        return true;
    }

    private function app(): Application
    {
        return $this->laravel;
    }
}
