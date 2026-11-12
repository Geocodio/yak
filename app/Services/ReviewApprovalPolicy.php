<?php

namespace App\Services;

use App\Channels\GitHub\AppService;
use App\DataTransferObjects\ParsedPriorFinding;
use App\DataTransferObjects\ParsedReview;
use App\Models\Repository;
use App\Support\ApprovalFloor;
use App\Support\PathMatcher;

class ReviewApprovalPolicy
{
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
        $installationId = (int) config('yak.channels.github.installation_id');
        try {
            $live = $github->getPullRequestOrFail($installationId, $repository->github_full_name, (int) ($metadata['pr_number'] ?? 0));
        } catch (\Throwable) {
            $live = null;
        }
        if ($live === null) {
            return ['Could not verify the pull request on GitHub.'];
        }

        $liveAuthor = (string) ($live['user']['login'] ?? '');
        if (strcasecmp($liveAuthor, $github->appBotLogin()) === 0) {
            return ['Yak opened this pull request.'];
        }
        if (trim($liveAuthor) === '' || trim((string) ($metadata['author'] ?? '')) === '') {
            return ['Pull request author is unknown.'];
        }
        if (($live['state'] ?? '') !== 'open' || ($live['draft'] ?? true) !== false || ($live['auto_merge'] ?? null) !== null
            || ($live['head']['repo']['full_name'] ?? null) !== ($live['base']['repo']['full_name'] ?? '')) {
            return ['Pull request is not open, is a draft, has auto-merge on, or comes from a fork.'];
        }
        if (($live['head']['sha'] ?? null) !== ($metadata['head_sha'] ?? '') || ($live['base']['ref'] ?? null) !== ($metadata['base_ref'] ?? '')) {
            return ['Pull request changed since the review; Yak will review again.'];
        }
        if (($live['changed_files'] ?? null) !== count($files)) {
            return ['Changed file list is incomplete.'];
        }

        $reviewExcludes = $repository->settings()->reviewPathExcludes() ?? (array) config('yak.pr_review.default_path_excludes', []);
        $concerns = $repository->settings()->concerns();
        if ($concerns === null) {
            return ['No concerns file (risk-profile.yml version 2) on the default branch.'];
        }

        $baseRef = (string) ($metadata['base_ref'] ?? '');
        try {
            $hasChecks = $github->hasRequiredStatusChecks(
                $installationId, $repository->github_full_name, $baseRef,
            );
        } catch (\Throwable) {
            $hasChecks = false;
        }
        $decision['observed']['required_checks_present'] = $hasChecks;
        if (! $hasChecks) {
            return ["Branch protection on {$baseRef} has no required status checks."];
        }

        $held = ApprovalFloor::heldPaths($files);
        $unreviewed = [];
        foreach ($files as $file) {
            $path = (string) ($file['filename'] ?? '');
            if (PathMatcher::matches($path, $reviewExcludes)) {
                $unreviewed[] = $path;
            }
        }
        if ($held !== [] || $unreviewed !== []) {
            return [
                ...array_map(fn (string $path): string => "Always needs a human: {$path}", $held),
                ...array_map(fn (string $path): string => "Not reviewed by Yak: {$path}", $unreviewed),
            ];
        }

        $reasons = [];
        if ($review->findings !== []) {
            $reasons[] = 'Review contains findings.';
        }
        if ($review->verdict !== 'Approve') {
            $reasons[] = 'Review verdict is not a clean approval.';
        }
        if (($metadata['review_scope'] ?? '') !== 'full') {
            $reasons[] = 'Approval requires a full review; request another review.';
        }
        foreach ($review->priorFindings as $prior) {
            if (in_array($prior->status, [ParsedPriorFinding::STATUS_STILL_OUTSTANDING, ParsedPriorFinding::STATUS_UNTOUCHED], true)) {
                $reasons[] = 'An earlier finding is still open.';
                break;
            }
        }
        if ($reasons !== []) {
            return $reasons;
        }

        $result = app(ConcernCheck::class)->check($concerns, array_values($files));
        $decision['classification'] = ['touched_concerns' => $result->touchedConcerns, 'change_kind' => $result->changeKind,
            'is_uncertain' => $result->isUncertain, 'failure' => $result->failure];
        if ($result->isUncertain || $result->failure !== null || $result->changeKind === null) {
            return [$result->failure ?? 'Concern check is uncertain.'];
        }

        $approvals = array_column($concerns, 'approval', 'name');
        $hasTestChange = $decision['observed']['has_test_change'];
        foreach ($result->touchedConcerns as $touched) {
            if (($approvals[$touched['name']] ?? 'always_human') !== 'when_tested') {
                $reasons[] = "Always needs a human: {$touched['name']}";
            } elseif (! $hasTestChange) {
                $reasons[] = "Touches {$touched['name']} without a test change.";
            }
        }
        if ($reasons !== []) {
            return $reasons;
        }

        $allowed = match ($level) {
            2 => ['no_runtime_change'],
            3 => ['no_runtime_change', 'presentation_or_internal'],
            4 => $hasTestChange ? ['no_runtime_change', 'presentation_or_internal', 'behavior_change'] : ['no_runtime_change', 'presentation_or_internal'],
            default => ['no_runtime_change', 'presentation_or_internal', 'behavior_change'],
        };

        return in_array($result->changeKind, $allowed, true) ? [] : ["DEFCON {$level} does not cover {$result->changeKind} changes."];
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
