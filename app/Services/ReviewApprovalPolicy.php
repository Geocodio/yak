<?php

namespace App\Services;

use App\Channels\GitHub\AppService;
use App\DataTransferObjects\ParsedReview;
use App\Enums\TaskMode;
use App\Models\Repository;
use App\Models\YakTask;
use App\Support\PathMatcher;

class ReviewApprovalPolicy
{
    /**
     * Evaluate the unfiltered review and complete PR file list, not the incremental
     * diff or the capped list of comments shown to the author. Fail closed.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<int, array<string, mixed>>  $files
     * @return array<string, mixed>
     */
    public function evaluate(Repository $repository, ParsedReview $review, array $metadata, array $files): array
    {
        $repository->refresh();
        $policy = $repository->reviewPolicy();
        $mode = $repository->is_active && $repository->pr_review_enabled ? ($policy['mode'] ?? 'off') : 'off';
        $assessment = app(ReviewRiskScorer::class)->assess($review->signals, (int) $policy['min_confidence']);
        $decision = ['event' => 'COMMENT', 'candidate' => 'COMMENT', 'reasons' => [], 'mode' => $mode,
            'risk_score' => $assessment['score'], 'model_confidence' => $assessment['model_confidence'],
            'score_components' => $assessment['components'], 'scoring_version' => ReviewRiskScorer::VERSION,
            'profile_version' => null, 'observed' => ['files' => count($files), 'ci_verified' => false]];

        if (! in_array($mode, ['shadow', 'enforce'], true)) {
            return $decision;
        }

        $reasons = $assessment['reasons'];
        $profile = app(RepositoryRiskProfiles::class)->active($repository->slug);
        $decision['profile_version'] = $profile['version'] ?? null;
        if ($profile === null || ($metadata['risk_profile_version'] ?? null) !== $profile['version']) {
            $reasons[] = 'Approved risk profile is missing, expired or changed during review.';
        }
        if (($profile['unknowns'] ?? []) !== []) {
            $reasons[] = 'Repository risk profile has unresolved context gaps.';
        }
        $mustFix = false;
        foreach ($review->findings as $finding) {
            $mustFix = $mustFix || $finding->severity === 'must_fix';
            $reasons[] = 'Review contains findings.';
        }

        if ($review->verdict !== 'Approve') {
            $reasons[] = 'Review verdict is not a clean approval.';
        }
        if ($review->risk !== 'low') {
            $reasons[] = 'Reviewer risk is high or unknown.';
        }
        if (($metadata['review_scope'] ?? '') !== 'full') {
            $reasons[] = 'Approval requires a full review; request another review.';
        }
        if ($review->priorFindings !== []) {
            $reasons[] = 'Prior findings require a fresh full review.';
        }

        $allowed = (array) ($policy['allowed_paths'] ?? []);
        $blocked = array_merge((array) config('yak.pr_review.approval_blocked_paths', []), $policy['blocked_paths']);
        $excludes = $repository->pr_review_path_excludes ?? (array) config('yak.pr_review.default_path_excludes', []);
        $lines = 0;
        $hasCode = false;
        $hasTests = false;
        $testDeletions = 0;
        $testSkips = false;

        if ($files === [] || count($files) > (int) $policy['max_files']) {
            $reasons[] = 'Missing files or file-count limit exceeded.';
        }
        foreach ($files as $file) {
            $path = (string) ($file['filename'] ?? '');
            $areaRisk = null;
            foreach (($profile['areas'] ?? []) as $area) {
                if (PathMatcher::matches($path, $area['paths'])) {
                    $floor = match ($area['risk']) {
                        'low' => 0, 'medium' => 50, 'high' => 75, default => 100,
                    };
                    $areaRisk = max($areaRisk ?? 0, $floor);
                }
            }
            if ($areaRisk === null || $areaRisk > 0) {
                $reasons[] = "Profile requires human review: {$path}";
            }
            if ($decision['risk_score'] !== null) {
                $decision['risk_score'] = max($decision['risk_score'], $areaRisk ?? 100);
            }
            if ($path === '' || ! PathMatcher::matches($path, $allowed)
                || PathMatcher::matches($path, $blocked) || PathMatcher::matches($path, $excludes)) {
                $reasons[] = "Path requires human review: {$path}";
            }
            if (! in_array($file['status'] ?? '', ['added', 'modified'], true)) {
                $reasons[] = 'Deleted, renamed or unknown file status.';
            }
            if (! isset($file['patch'], $file['additions'], $file['deletions'])
                || ! is_int($file['additions']) || ! is_int($file['deletions'])
                || $file['additions'] < 0 || $file['deletions'] < 0) {
                $reasons[] = 'Missing or invalid diff evidence.';

                continue;
            }
            $patch = $file['patch'];
            if (! is_string($patch) || trim($patch) === ''
                || preg_match_all('/^\+/m', $patch) !== $file['additions']
                || preg_match_all('/^-/m', $patch) !== $file['deletions']) {
                $reasons[] = 'Diff is empty, malformed or truncated.';
            }
            $lines += $file['additions'] + $file['deletions'];
            $test = str_starts_with($path, 'tests/');
            $hasTests = $hasTests || ($test && $file['additions'] > 0);
            $hasCode = $hasCode || (! $test && ! str_ends_with($path, '.md'));
            if ($test && $file['deletions'] > 0) {
                $testDeletions += $file['deletions'];
                $reasons[] = 'Existing tests were modified or removed.';
            }
            if ($test && preg_match('/^\+[^+].*(?:->skip\s*\(|\.(?:skip|todo)\s*\(|markTestSkipped\s*\()/m', (string) $file['patch']) === 1) {
                $testSkips = true;
                $reasons[] = 'Test skips or TODOs were introduced.';
            }
        }
        if ($lines > (int) $policy['max_lines']) {
            $reasons[] = 'Changed-line limit exceeded.';
        }
        if ($hasCode && ! $hasTests) {
            $reasons[] = 'Code change has no added test coverage.';
        }
        $decision['observed']['changed_lines'] = $lines;
        $decision['observed']['test_additions_present'] = $hasTests;
        $decision['observed']['test_lines_deleted'] = $testDeletions;
        $decision['observed']['test_skips_added'] = $testSkips;
        $writerAttempts = isset($metadata['pr_url']) ? YakTask::query()
            ->where('repo', $repository->slug)->where('pr_url', $metadata['pr_url'])
            ->where('mode', '!=', TaskMode::Review)->max('attempts') : null;
        $decision['observed']['writer_attempts'] = $writerAttempts === null ? null : (int) $writerAttempts;
        if ($writerAttempts !== null && (int) $writerAttempts > 1) {
            $reasons[] = 'Yak required multiple implementation attempts.';
        }
        $decision['observed']['reviewed_head_sha'] = $metadata['head_sha'] ?? null;
        if ($decision['risk_score'] === null || $decision['risk_score'] > (int) $policy['max_risk_score']) {
            $reasons[] = 'Risk score is unknown or exceeds the approval threshold of ' . $policy['max_risk_score'] . '.';
        }

        try {
            $github = app(AppService::class);
            $installationId = (int) config('yak.channels.github.installation_id');
            $pr = $github->getPullRequest($installationId, $repository->github_full_name, (int) $metadata['pr_number']);
            $current = ($pr['state'] ?? '') === 'open' && ($pr['draft'] ?? true) === false
                && ($pr['auto_merge'] ?? null) === null
                && ($pr['head']['sha'] ?? null) === ($metadata['head_sha'] ?? '')
                && ($pr['base']['sha'] ?? null) === ($metadata['base_sha'] ?? '')
                && ($pr['base']['ref'] ?? null) === ($metadata['base_ref'] ?? '')
                && ($pr['changed_files'] ?? null) === count($files)
                && ($pr['head']['repo']['full_name'] ?? null) === $repository->github_full_name;
            $author = (string) ($pr['user']['login'] ?? '');
            $canReview = $current && $author !== '' && strcasecmp($author, $github->appBotLogin()) !== 0;

            if (! $canReview) {
                $reasons[] = 'PR is stale, incomplete, draft, closed, forked, has auto-merge enabled or is authored by this reviewer.';
            } elseif ($mustFix) {
                $decision['candidate'] = 'REQUEST_CHANGES';
            } elseif ($reasons === []) {
                $evidence = $github->approvalEvidence(
                    $installationId, $repository->github_full_name, (int) $metadata['pr_number'],
                    (string) $metadata['head_sha'], (string) $pr['base']['ref'],
                );
                $reasons = $this->evidenceReasons(
                    $evidence, array_map('intval', array_column($policy['required_checks'], 'app_id', 'name')),
                    array_map('intval', array_column($policy['required_statuses'], 'creator_id', 'name')),
                );
                if ($reasons === []) {
                    $latest = $github->getPullRequest($installationId, $repository->github_full_name, (int) $metadata['pr_number']);
                    foreach (['state', 'draft', 'auto_merge', 'head', 'base', 'changed_files', 'user'] as $key) {
                        if (($latest[$key] ?? null) !== ($pr[$key] ?? null)) {
                            $reasons[] = 'PR changed while verifying CI; request a fresh review.';
                            break;
                        }
                    }
                }
                if ($reasons === []) {
                    $decision['candidate'] = 'APPROVE';
                    $decision['observed']['ci_verified'] = true;
                    $decision['observed']['ci'] = $evidence;
                }
            }
        } catch (\Throwable $e) {
            $reasons[] = 'GitHub approval evidence unavailable; human review required.';
        }

        $decision['reasons'] = array_values(array_unique($reasons));
        $decision['event'] = $mode === 'enforce' ? $decision['candidate'] : 'COMMENT';

        return $decision;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  array<string, int>  $requiredChecks
     * @param  array<string, int>  $requiredStatuses
     * @return array<int, string>
     */
    public function evidenceReasons(array $evidence, array $requiredChecks, array $requiredStatuses = []): array
    {
        if (($evidence['dismiss_stale_reviews'] ?? false) !== true) {
            return ['Branch protection must dismiss stale approvals.'];
        }
        if (($evidence['threads_clear'] ?? false) !== true) {
            return ['Review threads are unresolved or evidence is incomplete.'];
        }
        $checks = $evidence['check_runs'] ?? [];
        if (($requiredChecks === [] && $requiredStatuses === []) || ! is_array($checks) || count($checks) !== ($evidence['total_count'] ?? -1)) {
            return ['Required CI checks are unconfigured or evidence is incomplete.'];
        }
        foreach ($checks as $check) {
            if (($check['status'] ?? '') !== 'completed' || ($check['conclusion'] ?? '') !== 'success') {
                return ['CI contains pending, failed or skipped checks.'];
            }
        }
        foreach ($requiredChecks as $name => $appId) {
            $matches = array_filter($checks, fn (array $check): bool => ($check['name'] ?? '') === $name
                && (int) ($check['app']['id'] ?? 0) === $appId && $appId > 0);
            if ($matches === []) {
                return ["Required CI check missing from trusted app: {$name}"];
            }
        }

        $statuses = $evidence['statuses'] ?? [];
        if (! is_array($statuses) || count($statuses) !== ($evidence['status_count'] ?? -1)) {
            return ['Commit status evidence is incomplete.'];
        }
        foreach ($statuses as $status) {
            if (($status['state'] ?? '') !== 'success') {
                return ['Commit statuses contain pending or failed CI.'];
            }
        }
        foreach ($requiredStatuses as $context => $creatorId) {
            $matches = array_filter($statuses, fn (array $status): bool => ($status['context'] ?? '') === $context
                && (int) ($status['creator']['id'] ?? 0) === $creatorId && $creatorId > 0);
            if ($matches === []) {
                return ["Required CI status missing from trusted creator: {$context}"];
            }
        }

        return [];
    }
}
