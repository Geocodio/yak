<?php

use App\Actions\EnqueuePrReview;
use App\Channels\GitHub\AppService;
use App\DataTransferObjects\ParsedReview;
use App\DataTransferObjects\ReviewFinding;
use App\Jobs\RunYakReviewJob;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\RiskProfile;
use App\Models\YakTask;
use App\Services\RepositoryRiskProfiles;
use App\Services\ReviewApprovalPolicy;

beforeEach(function () {
    $this->repo = Repository::factory()->create(['slug' => 'acme/api', 'github_full_name' => 'acme/api', 'pr_review_enabled' => true, 'is_active' => true]);
    $this->metadata = ['pr_number' => 42, 'head_sha' => 'head', 'base_sha' => 'base', 'base_ref' => 'main', 'review_scope' => 'full'];
    $profiles = app(RepositoryRiskProfiles::class);
    $draft = $profiles->draft('acme/api', str_repeat('a', 40), json_encode([
        'areas' => [['name' => 'Docs', 'paths' => ['docs/**'], 'symbols' => [], 'risk' => 'low',
            'rationale' => 'Documentation only.', 'evidence' => ['docs/guide.md:1']]], 'unknowns' => [],
    ]));
    $profiles->approve('acme/api', $draft['version'], 'reviewer');
    $this->metadata['risk_profile_version'] = $draft['version'];
    $this->files = [['filename' => 'docs/guide.md', 'status' => 'modified', 'additions' => 2, 'deletions' => 1, 'patch' => "@@ -1 +1,2 @@\n-old\n+new\n+extra"]];
    $this->pr = [
        'state' => 'open', 'draft' => false, 'changed_files' => 1,
        'head' => ['sha' => 'head', 'repo' => ['full_name' => 'acme/api']],
        'base' => ['sha' => 'base', 'ref' => 'main'], 'user' => ['login' => 'alice'],
    ];
    $this->evidence = [
        'dismiss_stale_reviews' => true, 'threads_clear' => true, 'total_count' => 1,
        'statuses' => [], 'status_count' => 0,
        'check_runs' => [['name' => 'ci', 'app' => ['id' => 15368], 'status' => 'completed', 'conclusion' => 'success']],
    ];
    $this->repo->update(['pr_review_policy' => [
        'mode' => 'enforce', 'allowed_paths' => ['docs/**', 'app/**', 'tests/**', 'config/**'],
        'required_checks' => [['name' => 'ci', 'app_id' => 15368]],
    ]]);
    $github = mock(AppService::class);
    $github->shouldReceive('getPullRequest')->andReturnUsing(fn () => $this->pr);
    $github->shouldReceive('appBotLogin')->andReturn('yak[bot]');
    $github->shouldReceive('approvalEvidence')->andReturnUsing(fn () => $this->evidence);
    app()->instance(AppService::class, $github);
});

function cleanApprovalReview(string $risk = 'low', array $findings = []): ParsedReview
{
    $signals = ['model_confidence' => 90, 'uncertainties' => [], 'human_review_reasons' => []];
    foreach (['impact' => 1, 'blast_radius' => 1, 'behavior_change' => 0, 'verification_strength' => 3, 'context_completeness' => 3] as $key => $value) {
        $signals[$key] = ['value' => $value, 'explanation' => 'Checked against code.', 'references' => ['docs/guide.md:1']];
    }

    return new ParsedReview('Small documentation fix.', 'Approve', 'Verified.', $findings, risk: $risk, signals: $signals);
}

it('approves only an opted-in fully verified low-risk PR', function () {
    $decision = app(ReviewApprovalPolicy::class)->evaluate($this->repo, cleanApprovalReview(), $this->metadata, $this->files);
    expect($decision['event'])->toBe('APPROVE')->and($decision['reasons'])->toBe([]);
});

it('keeps shadow mode advisory', function () {
    $this->repo->update(['pr_review_policy' => array_replace($this->repo->reviewPolicy(), ['mode' => 'shadow'])]);
    $decision = app(ReviewApprovalPolicy::class)->evaluate($this->repo, cleanApprovalReview(), $this->metadata, $this->files);
    expect($decision['event'])->toBe('COMMENT')->and($decision['candidate'])->toBe('APPROVE');
});

it('requires a matching approved profile and refuses expired profiles', function (string $case) {
    if ($case === 'changed') {
        $this->metadata['risk_profile_version'] = 'another-version';
    } elseif ($case === 'expired') {
        $this->travel(91)->days();
    } else {
        RiskProfile::where('repo', 'acme/api')->update(['approved_by' => null, 'approved_at' => null]);
    }
    $result = app(ReviewApprovalPolicy::class)->evaluate($this->repo, cleanApprovalReview(), $this->metadata, $this->files);
    expect($result['event'])->toBe('COMMENT');
})->with(['changed', 'expired', 'missing']);

it('uses the highest matching profile risk even with perfect confidence', function () {
    $profiles = app(RepositoryRiskProfiles::class);
    $draft = $profiles->draft('acme/api', str_repeat('b', 40), json_encode([
        'areas' => [
            ['name' => 'Broad', 'paths' => ['docs/**'], 'symbols' => [], 'risk' => 'low', 'rationale' => 'Docs', 'evidence' => ['docs/guide.md:1']],
            ['name' => 'Critical', 'paths' => ['docs/guide.md'], 'symbols' => [], 'risk' => 'critical', 'rationale' => 'Executed instructions', 'evidence' => ['docs/guide.md:2']],
        ], 'unknowns' => [],
    ]));
    $profiles->approve('acme/api', $draft['version'], 'Reviewer');
    $this->metadata['risk_profile_version'] = $draft['version'];
    $review = cleanApprovalReview();
    $signals = $review->signals;
    $signals['model_confidence'] = 100;
    $review = new ParsedReview($review->summary, $review->verdict, $review->verdictDetail, [], risk: 'low', signals: $signals);
    $result = app(ReviewApprovalPolicy::class)->evaluate($this->repo, $review, $this->metadata, $this->files);
    expect($result['event'])->toBe('COMMENT')->and($result['risk_score'])->toBe(100);
});

it('does nothing without repository opt-in', function () {
    $this->repo->update(['pr_review_policy' => null]);
    app()->instance(AppService::class, mock(AppService::class));
    expect(app(ReviewApprovalPolicy::class)->evaluate($this->repo, cleanApprovalReview(), [], [])['event'])->toBe('COMMENT');
});

it('fails closed for unsafe or incomplete review inputs', function (string $case) {
    $review = cleanApprovalReview();
    switch ($case) {
        case 'unknown risk': $review = cleanApprovalReview('unknown');
            break;
        case 'high risk': $review = cleanApprovalReview('high');
            break;
        case 'incremental': $this->metadata['review_scope'] = 'incremental';
            break;
        case 'stale head': $this->pr['head']['sha'] = 'new';
            break;
        case 'stale base': $this->pr['base']['sha'] = 'new';
            break;
        case 'self review': $this->pr['user']['login'] = 'yak[bot]';
            break;
        case 'draft': $this->pr['draft'] = true;
            break;
        case 'auto merge': $this->pr['auto_merge'] = ['enabled_by' => ['login' => 'alice']];
            break;
        case 'closed': $this->pr['state'] = 'closed';
            break;
        case 'fork': $this->pr['head']['repo']['full_name'] = 'outsider/api';
            break;
        case 'incomplete files': $this->pr['changed_files'] = 2;
            break;
        case 'unknown path': $this->files[0]['filename'] = 'src/foo.php';
            break;
        case 'blocked path': $this->files[0]['filename'] = 'config/yak.php';
            break;
        case 'excluded path': $this->repo->update(['pr_review_path_excludes' => ['docs/**']]);
            break;
        case 'rename': $this->files[0]['status'] = 'renamed';
            break;
        case 'deletion': $this->files[0]['status'] = 'removed';
            break;
        case 'binary': unset($this->files[0]['patch']);
            break;
        case 'empty diff': $this->files[0]['patch'] = '';
            break;
        case 'truncated diff': $this->files[0]['patch'] = "@@ -1 +1,2 @@\n-old\n+new";
            break;
        case 'large': $this->files[0]['additions'] = 151;
            break;
        case 'untested code': $this->files[0]['filename'] = 'app/Foo.php';
            break;
        case 'weakened tests': $this->files[0]['filename'] = 'tests/FooTest.php';
            break;
        case 'finding': $review = cleanApprovalReview(findings: [new ReviewFinding('docs/guide.md', 1, 'should_fix', 'Tests', 'Missing case.')]);
            break;
    }
    $decision = app(ReviewApprovalPolicy::class)->evaluate($this->repo, $review, $this->metadata, $this->files);
    expect($decision['event'])->toBe('COMMENT')->and($decision['reasons'])->not->toBeEmpty();
})->with([
    'unknown risk', 'high risk', 'incremental', 'stale head', 'stale base', 'self review',
    'draft', 'auto merge', 'closed', 'fork', 'incomplete files', 'unknown path', 'blocked path',
    'excluded path', 'rename', 'deletion', 'binary', 'empty diff', 'truncated diff', 'large', 'untested code', 'weakened tests', 'finding',
]);

it('requests changes for a concrete blocker even when the model verdict says approve', function () {
    $review = cleanApprovalReview(findings: [new ReviewFinding('docs/guide.md', 1, 'must_fix', 'Correctness', 'Broken example.')]);
    expect(app(ReviewApprovalPolicy::class)->evaluate($this->repo, $review, $this->metadata, $this->files)['event'])->toBe('REQUEST_CHANGES');
});

it('does not request changes on its own PR', function () {
    $this->pr['user']['login'] = 'yak[bot]';
    $review = cleanApprovalReview(findings: [new ReviewFinding('docs/guide.md', 1, 'must_fix', 'Correctness', 'Broken.')]);
    expect(app(ReviewApprovalPolicy::class)->evaluate($this->repo, $review, $this->metadata, $this->files)['event'])->toBe('COMMENT');
});

it('fails closed when GitHub cannot provide evidence', function () {
    $github = mock(AppService::class);
    $github->shouldReceive('getPullRequest')->andThrow(new RuntimeException('403'));
    app()->instance(AppService::class, $github);
    expect(app(ReviewApprovalPolicy::class)->evaluate($this->repo, cleanApprovalReview(), $this->metadata, $this->files)['event'])->toBe('COMMENT');
});

it('reloads repository settings so disabling reviews stops queued approvals', function () {
    Repository::whereKey($this->repo->id)->update(['pr_review_enabled' => false]);
    app()->instance(AppService::class, mock(AppService::class));
    $decision = app(ReviewApprovalPolicy::class)->evaluate($this->repo, cleanApprovalReview(), $this->metadata, $this->files);
    expect($decision['event'])->toBe('COMMENT')->and($decision['mode'])->toBe('off');
});

it('requires complete successful CI from the configured app and stale-review dismissal', function (string $case) {
    $required = ['ci' => 15368];
    switch ($case) {
        case 'no dismissal': $this->evidence['dismiss_stale_reviews'] = false;
            break;
        case 'unresolved threads': $this->evidence['threads_clear'] = false;
            break;
        case 'truncated checks': $this->evidence['total_count'] = 101;
            break;
        case 'unconfigured checks': $required = [];
            break;
        case 'missing check': $required['phpstan'] = 15368;
            break;
        case 'wrong app': $this->evidence['check_runs'][0]['app']['id'] = 99;
            break;
        case 'pending': $this->evidence['check_runs'][0]['status'] = 'in_progress';
            break;
        case 'failed': $this->evidence['check_runs'][0]['conclusion'] = 'failure';
            break;
        case 'skipped': $this->evidence['check_runs'][0]['conclusion'] = 'skipped';
            break;
    }
    expect(app(ReviewApprovalPolicy::class)->evidenceReasons($this->evidence, $required))->not->toBeEmpty();
})->with(['no dismissal', 'unresolved threads', 'truncated checks', 'unconfigured checks', 'missing check', 'wrong app', 'pending', 'failed', 'skipped']);

it('upgrades re-review requests to full reviews for opted-in repositories', function () {
    $payload = $this->pr + ['html_url' => 'https://github.com/acme/api/pull/42', 'number' => 42];
    $payload['head']['ref'] = 'feature';
    $task = app(EnqueuePrReview::class)($this->repo, $payload, 'incremental', 'previous');
    $context = json_decode($task->context, true);
    expect($context['review_scope'])->toBe('full')->and($context['incremental_base_sha'])->toBeNull();
});

it('accepts Drone statuses only from the configured creator', function () {
    $this->evidence['check_runs'] = [];
    $this->evidence['total_count'] = 0;
    $this->evidence['statuses'] = [['context' => 'drone', 'state' => 'success', 'creator' => ['id' => 123]]];
    $this->evidence['status_count'] = 1;
    $policy = app(ReviewApprovalPolicy::class);
    expect($policy->evidenceReasons($this->evidence, [], ['drone' => 123]))->toBe([])
        ->and($policy->evidenceReasons($this->evidence, [], ['drone' => 456]))->not->toBeEmpty();
    $this->evidence['statuses'][0]['state'] = 'pending';
    expect($policy->evidenceReasons($this->evidence, [], ['drone' => 123]))->not->toBeEmpty();
});

it('posts the policy decision through the review job and preserves the SHA on fallback', function (bool $reject) {
    $task = YakTask::factory()->create([
        'repo' => $this->repo->slug, 'pr_url' => 'https://github.com/acme/api/pull/42',
    ]);
    $github = mock(AppService::class);
    $github->shouldReceive('getPullRequest')->andReturn($this->pr);
    $github->shouldReceive('appBotLogin')->andReturn('yak[bot]');
    $github->shouldReceive('approvalEvidence')->andReturn($this->evidence);
    $github->shouldReceive('listPullRequestFiles')->andReturn($this->files);
    $submission = $github->shouldReceive('createPullRequestReview')->once()
        ->withArgs(fn ($i, $repo, $pr, $body, $event, $comments, $sha): bool => $event === 'APPROVE'
            && $sha === 'head' && str_contains($body, 'Yak review policy'));
    if ($reject) {
        $submission->andThrow(new RuntimeException('GitHub rejected review'));
        $github->shouldReceive('createPullRequestReview')->once()
            ->withArgs(fn ($i, $repo, $pr, $body, $event, $comments, $sha): bool => $event === 'COMMENT'
                && $sha === 'head' && $comments === [] && str_contains($body, 'comment only'))
            ->andReturn(['id' => 123]);
    } else {
        $submission->andReturn(['id' => 123]);
    }
    app()->instance(AppService::class, $github);

    $method = new ReflectionMethod(RunYakReviewJob::class, 'postReview');
    $method->invoke(new RunYakReviewJob($task), $this->repo, cleanApprovalReview(), $this->metadata);
    expect(PrReview::first()->commit_sha_reviewed)->toBe('head')
        ->and(PrReview::first()->risk_assessment['risk_score'])->toBe(25)
        ->and(PrReview::first()->risk_assessment['event'])->toBe($reject ? 'COMMENT' : 'APPROVE')
        ->and(PrReview::first()->risk_assessment['signals']['model_confidence'])->toBe(90);
})->with([false, true]);

it('does not approve when the PR changes while CI evidence is fetched', function (string $change) {
    $github = mock(AppService::class);
    $github->shouldReceive('getPullRequest')->andReturnUsing(fn () => $this->pr);
    $github->shouldReceive('appBotLogin')->andReturn('yak[bot]');
    $github->shouldReceive('approvalEvidence')->once()->andReturnUsing(function () use ($change) {
        if ($change === 'head') {
            $this->pr['head']['sha'] = 'new-head';
        } elseif ($change === 'base') {
            $this->pr['base']['sha'] = 'new-base';
        } else {
            $this->pr['auto_merge'] = ['enabled_by' => ['login' => 'alice']];
        }

        return $this->evidence;
    });
    app()->instance(AppService::class, $github);
    $decision = app(ReviewApprovalPolicy::class)->evaluate($this->repo, cleanApprovalReview(), $this->metadata, $this->files);
    expect($decision['event'])->toBe('COMMENT')
        ->and($decision['reasons'])->toContain('PR changed while verifying CI; request a fresh review.');
})->with(['head', 'base', 'auto_merge']);

it('still approves when only volatile repository metadata changes between fetches', function () {
    $github = mock(AppService::class);
    $github->shouldReceive('getPullRequest')->andReturnUsing(fn () => $this->pr);
    $github->shouldReceive('appBotLogin')->andReturn('yak[bot]');
    $github->shouldReceive('approvalEvidence')->once()->andReturnUsing(function () {
        // An unrelated push to the repository, or a new issue being filed, bumps
        // these fields on the refetched PR's nested repo objects without the PR
        // itself changing; that must not block approval.
        $this->pr['head']['repo']['pushed_at'] = now()->addMinute()->toIso8601String();
        $this->pr['base']['repo']['open_issues_count'] = 7;

        return $this->evidence;
    });
    app()->instance(AppService::class, $github);
    $decision = app(ReviewApprovalPolicy::class)->evaluate($this->repo, cleanApprovalReview(), $this->metadata, $this->files);
    expect($decision['event'])->toBe('APPROVE')->and($decision['reasons'])->toBe([]);
});

it('requires human review for the approval machinery itself', function (string $path) {
    $this->files[0]['filename'] = $path;
    $decision = app(ReviewApprovalPolicy::class)->evaluate($this->repo, cleanApprovalReview(), $this->metadata, $this->files);
    expect($decision['event'])->toBe('COMMENT')
        ->and($decision['reasons'])->toContain("Path requires human review: {$path}");
})->with([
    'app/Services/RepositoryRiskProfiles.php', 'app/YakPromptBuilder.php',
    'app/Channels/GitHub/AppService.php', 'app/Jobs/ResearchYakJob.php', 'app/Support/PathMatcher.php',
]);

it('does not request changes for a must_fix the author never sees', function () {
    $this->repo->update(['pr_review_path_excludes' => ['docs/**']]);
    $review = cleanApprovalReview(findings: [new ReviewFinding('docs/guide.md', 1, 'must_fix', 'Correctness', 'Broken example.')]);
    $decision = app(ReviewApprovalPolicy::class)->evaluate($this->repo, $review, $this->metadata, $this->files);
    expect($decision['event'])->toBe('COMMENT')
        ->and($decision['reasons'])->toContain('Review contains findings.');
});

it('recognises test coverage outside the Laravel tests directory', function (string $testFile) {
    $profiles = app(RepositoryRiskProfiles::class);
    $draft = $profiles->draft('acme/api', str_repeat('c', 40), json_encode([
        'areas' => [['name' => 'Library', 'paths' => ['src/**', 'spec/**', '__tests__/**'], 'symbols' => [],
            'risk' => 'low', 'rationale' => 'Isolated helper.', 'evidence' => ['src/slug.js:1']]], 'unknowns' => [],
    ]));
    $profiles->approve('acme/api', $draft['version'], 'reviewer');
    $this->metadata['risk_profile_version'] = $draft['version'];
    $this->repo->update(['pr_review_policy' => array_replace($this->repo->reviewPolicy(), [
        'allowed_paths' => ['src/**', 'spec/**', '__tests__/**'],
    ])]);
    $this->pr['changed_files'] = 2;
    $this->files = [
        ['filename' => 'src/slug.js', 'status' => 'modified', 'additions' => 1, 'deletions' => 1, 'patch' => "@@ -1 +1 @@\n-old\n+new"],
        ['filename' => $testFile, 'status' => 'added', 'additions' => 2, 'deletions' => 0, 'patch' => "@@ -0,0 +1,2 @@\n+describe\n+expect"],
    ];

    $decision = app(ReviewApprovalPolicy::class)->evaluate($this->repo, cleanApprovalReview(), $this->metadata, $this->files);
    expect($decision['reasons'])->toBe([])->and($decision['event'])->toBe('APPROVE');
})->with(['spec/slug_spec.rb', '__tests__/slug.test.js']);
