<?php

namespace App\Services;

use App\Channels\GitHub\AppService;
use App\DataTransferObjects\ConcernCheckResult;
use App\DataTransferObjects\ParsedPriorFinding;
use App\DataTransferObjects\ParsedReview;
use App\DataTransferObjects\ReviewFinding;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Repository;
use App\Support\ApprovalFloor;
use App\Support\PathMatcher;
use Illuminate\Support\Facades\Cache;

class ReviewApprovalPolicy
{
    private const CONCERNS_MISSING = 'No concerns file (risk-profile.yml version 2) on the default branch.';

    /**
     * Decide whether the latest review of a pull request may be approved. Checks run
     * in order and the first failing stage ends the evaluation. Fail closed.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<int, array<string, mixed>>  $files  The complete PR file list
     * @return array<string, mixed>
     */
    public function evaluate(Repository $repository, ParsedReview $review, array $metadata, array $files): array
    {
        $repository->refresh();
        $settings = $repository->settings();
        $level = $repository->approvalLevel();
        $mode = $repository->is_active && $settings->reviewEnabled() ? $repository->approvalMode() : 'off';
        $hasTestChange = $this->hasTestChange($files);
        $decision = ['event' => 'COMMENT', 'candidate' => 'COMMENT', 'reasons' => [], 'mode' => $mode, 'level' => $level,
            'concerns_version' => $settings->concernsVersion(), 'classification' => null,
            'observed' => ['files' => count($files), 'has_test_change' => $hasTestChange, 'required_checks_present' => null]];

        if (! in_array($mode, ['shadow', 'enforce'], true) || $level < 2) {
            $decision['mode'] = 'off';

            return $decision;
        }

        $github = app(AppService::class);
        $isYakAuthored = strcasecmp((string) ($metadata['author'] ?? ''), $github->appBotLogin()) === 0;
        $excludes = $settings->reviewPathExcludes() ?? (array) config('yak.pr_review.default_path_excludes', []);
        $mustFix = false;
        foreach ($review->findings as $finding) {
            // Only a finding the author can see may turn the review into a change
            // request; an excluded path is never shown in the review body.
            $mustFix = $mustFix || ($finding->severity === 'must_fix' && ! PathMatcher::matches($finding->file, $excludes));
        }

        $reasons = $this->holdReasons($repository, $review, $metadata, $files, $level, $isYakAuthored, $github, $decision);
        if ($mustFix && ! $isYakAuthored) {
            $decision['candidate'] = 'REQUEST_CHANGES';
        } elseif ($reasons === []) {
            $decision['candidate'] = 'APPROVE';
        }

        $decision['reasons'] = array_values(array_unique($reasons));
        $decision['event'] = $mode === 'enforce' ? $decision['candidate'] : 'COMMENT';

        return $decision;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, array<string, mixed>>  $files
     * @param  array<string, mixed>  $decision
     * @return list<string>
     */
    private function holdReasons(Repository $repository, ParsedReview $review, array $metadata, array $files, int $level, bool $isYakAuthored, AppService $github, array &$decision): array
    {
        if ($isYakAuthored) {
            return ['Yak opened this pull request.'];
        }
        try {
            $live = $github->getPullRequestOrFail((int) config('yak.channels.github.installation_id'), $repository->github_full_name, (int) ($metadata['pr_number'] ?? 0));
        } catch (\Throwable) {
            $live = null;
        }
        if ($live === null) {
            return ['Could not verify the pull request on GitHub.'];
        }

        $liveAuthor = (string) ($live['user']['login'] ?? '');
        $hold = $this->authorHold($github, $liveAuthor, (string) ($metadata['author'] ?? ''))
            ?? $this->pullRequestStateHold($live)
            ?? $this->yakCommitHold($github, $repository, (int) ($metadata['pr_number'] ?? 0), $live['commits'] ?? null);
        if ($hold !== null) {
            return [$hold];
        }
        if (($live['head']['sha'] ?? null) !== ($metadata['head_sha'] ?? '') || ($live['base']['ref'] ?? null) !== ($metadata['base_ref'] ?? '')) {
            return ['Pull request changed since the review; Yak will review again.'];
        }
        if (($live['changed_files'] ?? null) !== count($files)) {
            return ['Changed file list is incomplete.'];
        }

        $reviewExcludes = $repository->settings()->reviewPathExcludes() ?? (array) config('yak.pr_review.default_path_excludes', []);
        $concerns = $repository->settings()->concerns();
        $baseRef = (string) ($metadata['base_ref'] ?? '');
        if ($concerns === null) {
            return [self::CONCERNS_MISSING];
        }
        $repositoryHold = $this->requiredChecksHold($repository, $github, $baseRef, $decision['observed']['required_checks_present']);
        if ($repositoryHold !== null) {
            return [$repositoryHold];
        }

        $floorHolds = $this->floorHolds($files, $reviewExcludes);
        if ($floorHolds !== []) {
            return $floorHolds;
        }

        $reasons = $this->reviewContentHolds($review, (string) ($metadata['review_scope'] ?? ''), $metadata);
        if ($reasons !== []) {
            return $reasons;
        }

        $result = app(ConcernCheck::class)->check($concerns, array_values($this->withMissingPatches($repository, $live, $files)));
        $decision['classification'] = $this->classificationFrom($result);
        $reasons = $this->concernHolds($concerns, $decision['classification'], $decision['observed']['has_test_change']);
        if ($reasons !== []) {
            return $reasons;
        }

        $allowed = $this->allowedChangeKinds($level, $decision['observed']['has_test_change']);

        return in_array($result->changeKind, $allowed, true) ? [] : ["DEFCON {$level} does not cover {$result->changeKind} changes."];
    }

    /**
     * Dry-run the hold checks against an open pull request without posting
     * anything or writing a review. Ignores the repository's mode and level, and
     * leaves out the repository-wide holds ({@see self::repositoryHolds()}).
     * The per-pull-request holds keep the order and wording `evaluate()` uses.
     * Without a review at the head commit the result is `waiting` and the
     * review-content and concern checks are skipped. A given `$classification`
     * replaces the model call; with none, the model runs only when nothing else
     * already makes the pull request human-only at every level.
     *
     * @param  array<string, mixed>  $pullRequest  A pull request from the open-pull-request listing
     * @param  array<int, array<string, mixed>>  $files
     * @param  array{touched_concerns: list<array{name: string, evidence: string}>, change_kind: ?string, is_uncertain: bool, failure: ?string}|null  $classification
     * @param  list<string>|null  $repositoryHolds  Precomputed {@see self::repositoryHolds()}
     * @return array{holds: list<string>, changeKind: ?string, hasTestChange: bool, waiting: bool, classification: array{touched_concerns: list<array{name: string, evidence: string}>, change_kind: ?string, is_uncertain: bool, failure: ?string}|null}
     */
    public function preview(Repository $repository, array $pullRequest, array $files, ?PrReview $latestReview, ?array $classification, ?array $repositoryHolds = null): array
    {
        $baseRef = (string) ($pullRequest['base']['ref'] ?? $repository->default_branch);
        $baseHolds = $baseRef === $repository->default_branch ? [] : $this->branchHolds($repository, $baseRef);
        $outcome = $this->previewWithoutBaseHolds($repository, $pullRequest, $files, $latestReview, $classification, $repositoryHolds, $baseHolds !== []);
        $outcome['holds'] = array_values(array_unique([...$outcome['holds'], ...$baseHolds]));

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $pullRequest
     * @param  array<int, array<string, mixed>>  $files
     * @param  array{touched_concerns: list<array{name: string, evidence: string}>, change_kind: ?string, is_uncertain: bool, failure: ?string}|null  $classification
     * @param  list<string>|null  $repositoryHolds
     * @return array{holds: list<string>, changeKind: ?string, hasTestChange: bool, waiting: bool, classification: array{touched_concerns: list<array{name: string, evidence: string}>, change_kind: ?string, is_uncertain: bool, failure: ?string}|null}
     */
    private function previewWithoutBaseHolds(Repository $repository, array $pullRequest, array $files, ?PrReview $latestReview, ?array $classification, ?array $repositoryHolds, bool $hasBaseHolds): array
    {
        $hasTestChange = $this->hasTestChange($files);
        $outcome = ['holds' => [], 'changeKind' => null, 'hasTestChange' => $hasTestChange, 'waiting' => $latestReview === null, 'classification' => null];
        $github = app(AppService::class);
        $author = (string) ($pullRequest['user']['login'] ?? '');

        $hold = $this->authorHold($github, $author, $author)
            ?? $this->pullRequestStateHold($pullRequest)
            ?? (isset($pullRequest['changed_files']) && $pullRequest['changed_files'] !== count($files) ? 'Changed file list is incomplete.' : null);
        if ($hold !== null) {
            return [...$outcome, 'holds' => [$hold]];
        }

        $reviewExcludes = $repository->settings()->reviewPathExcludes() ?? (array) config('yak.pr_review.default_path_excludes', []);
        $floorHolds = $this->floorHolds($files, $reviewExcludes);
        if ($floorHolds !== [] || $latestReview === null) {
            return [...$outcome, 'holds' => $floorHolds];
        }

        $reasons = $this->reviewContentHolds($this->parsedReviewFrom($latestReview), $latestReview->review_scope ?? '', ['pr_url' => $latestReview->pr_url]);
        if ($reasons !== []) {
            return [...$outcome, 'holds' => $reasons];
        }

        $concerns = $repository->settings()->concerns();
        $repositoryHolds ??= $this->repositoryHolds($repository);
        if ($concerns === null || $repositoryHolds !== [] || $hasBaseHolds) {
            return $outcome;
        }

        if ($classification === null) {
            $classification = $this->classificationFrom(app(ConcernCheck::class)->check($concerns, array_values($this->withMissingPatches($repository, $pullRequest, $files))));
        }
        $outcome['classification'] = $classification;
        if ($classification['is_uncertain'] || $classification['failure'] !== null || $classification['change_kind'] === null) {
            return [...$outcome, 'holds' => [$classification['failure'] ?? 'Concern check is uncertain.']];
        }

        return [...$outcome, 'holds' => $this->concernHolds($concerns, $classification, $hasTestChange), 'changeKind' => $classification['change_kind']];
    }

    /**
     * Holds that apply to every pull request of the repository, read against the
     * default branch. Unlike `evaluate()` this lists every failing check.
     *
     * @return list<string>
     */
    public function repositoryHolds(Repository $repository): array
    {
        return array_values(array_filter([
            $repository->settings()->concerns() === null ? self::CONCERNS_MISSING : null,
            ...$this->branchHolds($repository, $repository->default_branch),
        ]));
    }

    /**
     * Branch protection holds of one base branch, remembered for a minute so
     * the per-pull-request checks do not each ask GitHub again.
     *
     * @return list<string>
     */
    public function branchHolds(Repository $repository, string $baseRef, bool $fresh = false): array
    {
        $key = "approval-branch-holds:{$repository->id}:" . md5($baseRef);
        if (! $fresh && is_array($cached = Cache::get($key))) {
            /** @var list<string> $cached */
            return $cached;
        }

        $github = app(AppService::class);
        $unused = null;
        $hold = $this->requiredChecksHold($repository, $github, $baseRef, $unused);
        $holds = $hold === null ? [] : [$hold];
        Cache::put($key, $holds, 60);

        return $holds;
    }

    /**
     * Yak never approves its own code: a commit Yak wrote or co-authored
     * needs a person's approval, the same as a pull request Yak opened.
     * Fails closed unless every commit was read: the listing returns an
     * empty list on a GitHub error and stops at 250 commits, so its count
     * must match the live pull request's commit count.
     */
    private function yakCommitHold(AppService $github, Repository $repository, int $prNumber, mixed $liveCommitCount): ?string
    {
        try {
            $commits = $github->listPullRequestCommits((int) config('yak.channels.github.installation_id'), $repository->github_full_name, $prNumber);
        } catch (\Throwable) {
            $commits = null;
        }

        if (! is_int($liveCommitCount) || ! is_array($commits) || count($commits) !== $liveCommitCount) {
            return 'Could not verify the pull request commits.';
        }

        $botLogin = $github->appBotLogin();
        $yakEmail = strtolower((string) config('yak.git_user_email'));

        foreach ($commits as $commit) {
            $isYakCommit = strcasecmp((string) ($commit['author']['login'] ?? ''), $botLogin) === 0
                || strtolower((string) ($commit['commit']['author']['email'] ?? '')) === $yakEmail
                || strtolower((string) ($commit['commit']['committer']['email'] ?? '')) === $yakEmail
                || str_contains(strtolower((string) ($commit['commit']['message'] ?? '')), "co-authored-by: yak <{$yakEmail}>");

            if ($isYakCommit) {
                return 'Yak wrote commits on this pull request.';
            }
        }

        return null;
    }

    private function authorHold(AppService $github, string $author, string $reviewedAuthor): ?string
    {
        if (strcasecmp($author, $github->appBotLogin()) === 0) {
            return 'Yak opened this pull request.';
        }

        return trim($author) === '' || trim($reviewedAuthor) === '' ? 'Pull request author is unknown.' : null;
    }

    /**
     * @param  array<string, mixed>  $pullRequest
     */
    private function pullRequestStateHold(array $pullRequest): ?string
    {
        return match (true) {
            ($pullRequest['state'] ?? '') !== 'open' => 'Pull request is not open.',
            ($pullRequest['draft'] ?? true) !== false => 'Pull request is a draft.',
            ($pullRequest['auto_merge'] ?? null) !== null => 'Pull request has auto-merge on.',
            ($pullRequest['head']['repo']['full_name'] ?? null) !== ($pullRequest['base']['repo']['full_name'] ?? '') => 'Pull request comes from a fork.',
            default => null,
        };
    }

    /**
     * `$observed` stays null when GitHub could not be read.
     *
     * @param-out bool|null $observed
     */
    private function requiredChecksHold(Repository $repository, AppService $github, string $baseRef, ?bool &$observed): ?string
    {
        try {
            $hasChecks = $github->hasRequiredStatusChecks((int) config('yak.channels.github.installation_id'), $repository->github_full_name, $baseRef);
        } catch (\Throwable) {
            $observed = null;

            return "Could not read branch protection on {$baseRef}.";
        }
        $observed = $hasChecks;

        return $hasChecks ? null : "Branch protection on {$baseRef} has no required status checks.";
    }

    /**
     * GitHub's file list leaves out the patch of a file whose diff is too large.
     * Fill those patches from the compare diff of the pull request's base and
     * head commits. A file with no text hunks there (a binary file) keeps no
     * patch, so the concern check still holds it.
     *
     * @param  array<string, mixed>  $pullRequest
     * @param  array<int, array<string, mixed>>  $files
     * @return array<int, array<string, mixed>>
     */
    private function withMissingPatches(Repository $repository, array $pullRequest, array $files): array
    {
        $isMissingPatch = fn (array $file): bool => ! is_string($file['patch'] ?? null);
        $baseSha = (string) ($pullRequest['base']['sha'] ?? '');
        $headSha = (string) ($pullRequest['head']['sha'] ?? '');
        if ($baseSha === '' || $headSha === '' || array_filter($files, $isMissingPatch) === []) {
            return $files;
        }

        try {
            $diff = app(AppService::class)->getCompareDiff((int) config('yak.channels.github.installation_id'), $repository->github_full_name, $baseSha, $headSha);
        } catch (\Throwable) {
            return $files;
        }
        if ($diff === null) {
            return $files;
        }

        $sections = [];
        foreach (preg_split('/^(?=diff --git )/m', $diff) ?: [] as $section) {
            $header = strtok($section, "\n");
            $hunkStart = strpos($section, "\n@@");
            if ($header !== false && $hunkStart !== false) {
                $sections[$header] = rtrim(substr($section, $hunkStart + 1), "\n");
            }
        }

        foreach ($files as $index => $file) {
            if ($isMissingPatch($file)) {
                $filename = (string) ($file['filename'] ?? '');
                $previous = (string) ($file['previous_filename'] ?? $filename);
                $patch = $sections["diff --git a/{$previous} b/{$filename}"] ?? null;
                if ($patch !== null) {
                    $files[$index]['patch'] = $patch;
                }
            }
        }

        return $files;
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     * @param  array<int, string>  $reviewExcludes
     * @return list<string>
     */
    private function floorHolds(array $files, array $reviewExcludes): array
    {
        $held = ApprovalFloor::heldPaths($files);
        $unreviewed = [];
        foreach ($files as $file) {
            $path = (string) ($file['filename'] ?? '');
            if (PathMatcher::matches($path, $reviewExcludes)) {
                $unreviewed[] = $path;
            }
        }

        return [
            ...array_map(fn (string $path): string => "Always needs a human: {$path}", $held),
            ...array_map(fn (string $path): string => "Not reviewed by Yak: {$path}", $unreviewed),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    private function reviewContentHolds(ParsedReview $review, string $reviewScope, array $metadata): array
    {
        $reasons = [];
        if ($review->findings !== []) {
            $reasons[] = 'Review contains findings.';
        }
        if ($review->verdict !== 'Approve') {
            $reasons[] = 'Review verdict is not a clean approval.';
        }
        if ($reviewScope !== 'full') {
            $reasons[] = 'Approval requires a full review; request another review.';
        }
        foreach ($review->priorFindings as $prior) {
            if (in_array($prior->status, [ParsedPriorFinding::STATUS_STILL_OUTSTANDING, ParsedPriorFinding::STATUS_UNTOUCHED], true)) {
                $reasons[] = 'An earlier finding is still open.';
                break;
            }
        }
        array_push($reasons, ...$this->earlierFindingReasons($review, $metadata));

        return $reasons;
    }

    /**
     * @return array{touched_concerns: list<array{name: string, evidence: string}>, change_kind: ?string, is_uncertain: bool, failure: ?string}
     */
    private function classificationFrom(ConcernCheckResult $result): array
    {
        return ['touched_concerns' => $result->touchedConcerns, 'change_kind' => $result->changeKind,
            'is_uncertain' => $result->isUncertain, 'failure' => $result->failure];
    }

    /**
     * @param  list<array{name: string, approval: 'always_human'|'when_tested', description: string}>  $concerns
     * @param  array{touched_concerns: list<array{name: string, evidence: string}>, change_kind: ?string, is_uncertain: bool, failure: ?string}  $classification
     * @return list<string>
     */
    private function concernHolds(array $concerns, array $classification, bool $hasTestChange): array
    {
        if ($classification['is_uncertain'] || $classification['failure'] !== null || $classification['change_kind'] === null) {
            return [$classification['failure'] ?? 'Concern check is uncertain.'];
        }

        $reasons = [];
        $approvals = array_column($concerns, 'approval', 'name');
        foreach ($classification['touched_concerns'] as $touched) {
            if (($approvals[$touched['name']] ?? 'always_human') !== 'when_tested') {
                $reasons[] = "Always needs a human: {$touched['name']}";
            } elseif (! $hasTestChange) {
                $reasons[] = "Touches {$touched['name']} without a test change.";
            }
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private function allowedChangeKinds(int $level, bool $hasTestChange): array
    {
        return match ($level) {
            2 => ['no_runtime_change'],
            3 => ['no_runtime_change', 'presentation_or_internal'],
            4 => $hasTestChange ? ['no_runtime_change', 'presentation_or_internal', 'behavior_change'] : ['no_runtime_change', 'presentation_or_internal'],
            default => ['no_runtime_change', 'presentation_or_internal', 'behavior_change'],
        };
    }

    /**
     * Rebuild the parts of a review the hold checks read from a stored review:
     * its verdict and the findings stored as comment rows. A finding count above
     * the stored rows counts as findings too.
     */
    private function parsedReviewFrom(PrReview $review): ParsedReview
    {
        $findings = $review->comments->map(fn (PrReviewComment $comment): ReviewFinding => new ReviewFinding(
            (string) $comment->file_path, (int) $comment->line_number, (string) $comment->severity, (string) $comment->category, (string) $comment->body,
        ))->all();
        for ($missing = (int) ($review->risk_assessment['findings_count'] ?? 0) - count($findings); $missing > 0; $missing--) {
            $findings[] = new ReviewFinding('', 0, 'consider', '', '');
        }

        return new ParsedReview((string) $review->summary, (string) $review->verdict, '', $findings);
    }

    /**
     * Holds while any stored earlier Yak finding on this pull request is open. A
     * finding is closed only when Yak recorded it as fixed or withdrawn, or this
     * round's review reports it so. A resolved GitHub thread proves nothing: the
     * pull request author can resolve threads. Independent of what the reviewer
     * chose to report.
     *
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    private function earlierFindingReasons(ParsedReview $review, array $metadata): array
    {
        $prUrl = (string) ($metadata['pr_url'] ?? '');
        if ($prUrl === '') {
            return ['Could not verify earlier findings.'];
        }

        $closedStatuses = [ParsedPriorFinding::STATUS_FIXED, ParsedPriorFinding::STATUS_WITHDRAWN];
        $reportedClosed = [];
        foreach ($review->priorFindings as $prior) {
            if (in_array($prior->status, $closedStatuses, true)) {
                $reportedClosed[$prior->commentId] = true;
            }
        }

        $comments = PrReviewComment::query()
            ->whereHas('review', fn ($query) => $query->where('pr_url', $prUrl))
            ->get(['github_comment_id', 'resolution_status']);
        foreach ($comments as $comment) {
            $isClosed = in_array($comment->resolution_status, $closedStatuses, true)
                || isset($reportedClosed[(int) $comment->github_comment_id]);
            if (! $isClosed) {
                return ['An earlier Yak finding is still open.'];
            }
        }

        return [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     */
    private function hasTestChange(array $files): bool
    {
        $testPaths = (array) config('yak.pr_review.approval_test_paths', []);
        foreach ($files as $file) {
            if (in_array($file['status'] ?? '', ['added', 'modified'], true)
                && PathMatcher::matches((string) ($file['filename'] ?? ''), $testPaths)) {
                return true;
            }
        }

        return false;
    }
}
