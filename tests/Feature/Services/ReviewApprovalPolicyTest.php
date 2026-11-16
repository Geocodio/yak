<?php

use App\Ai\Agents\ConcernClassifier;
use App\Channels\GitHub\AppService;
use App\DataTransferObjects\ParsedPriorFinding;
use App\DataTransferObjects\ParsedReview;
use App\DataTransferObjects\ReviewFinding;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Repository;
use App\Services\ReviewApprovalPolicy;
use Laravel\Ai\Ai;

const POLICY_PR_URL = 'https://github.com/acme/api/pull/42';
const BILLING = 'Customer charges and Stripe writes';
const METERING = 'Usage metering and credit accounting';

function policyReview(array $findings = [], array $priorFindings = [], string $verdict = 'Approve'): ParsedReview
{
    return new ParsedReview('Summary.', $verdict, 'Verified.', $findings, $priorFindings);
}

function policyAnswer(string $kind = 'presentation_or_internal', array $touched = []): array
{
    return ['touched_concerns' => $touched, 'change_kind' => $kind, 'is_uncertain' => false];
}

function policyFiles(string ...$paths): array
{
    return array_map(fn (string $path): array => ['filename' => $path, 'status' => 'modified', 'patch' => '+x'], $paths ?: ['app/Thing.php']);
}

function policyEvaluate(array $files, ParsedReview $review, array $metadata = []): array
{
    return app(ReviewApprovalPolicy::class)->evaluate(
        test()->repo, $review,
        array_merge(['pr_number' => 42, 'head_sha' => 'head', 'base_ref' => 'main', 'review_scope' => 'full', 'author' => 'alice', 'pr_url' => POLICY_PR_URL], $metadata),
        $files,
    );
}

function fakePolicyClassifier(string $kind = 'presentation_or_internal', array $touched = []): void
{
    Ai::fakeAgent(ConcernClassifier::class, [policyAnswer($kind, $touched), policyAnswer($kind, $touched)]);
}

beforeEach(function () {
    config(['ai.providers.anthropic.key' => 'sk-ant-test']);
    $this->repo = Repository::factory()->create(['slug' => 'acme/api', 'github_full_name' => 'acme/api', 'pr_review_enabled' => true, 'is_active' => true]);
    $this->repo->setApproval(5, 'shadow', null);
    fakeYakFiles(['risk-profile.yml' => concernsYaml()]);
    $this->github = mock(AppService::class);
    $this->github->shouldReceive('appBotLogin')->andReturn('yak[bot]');
    $this->livePullRequest = [
        'state' => 'open', 'draft' => false, 'auto_merge' => null, 'changed_files' => 1,
        'head' => ['sha' => 'head', 'repo' => ['full_name' => 'acme/api']],
        'base' => ['ref' => 'main', 'repo' => ['full_name' => 'acme/api']], 'user' => ['login' => 'alice'],
    ];
    $this->github->shouldReceive('getPullRequestOrFail')->andReturnUsing(fn () => $this->livePullRequest)->byDefault();
    $this->github->shouldReceive('hasRequiredStatusChecks')->andReturn(true)->byDefault();
    app()->instance(AppService::class, $this->github);
});

it('stays off at level 1 and for inactive repositories', function () {
    $this->repo->setApproval(1, 'shadow', null);
    $decision = policyEvaluate(policyFiles(), policyReview());
    expect($decision['mode'])->toBe('off')->and($decision['event'])->toBe('COMMENT')->and($decision['reasons'])->toBe([]);

    $this->repo->setApproval(5, 'enforce', null);
    $this->repo->update(['is_active' => false]);
    expect(policyEvaluate(policyFiles(), policyReview())['mode'])->toBe('off');
});

it('holds a Yak-opened pull request', function () {
    $decision = policyEvaluate(policyFiles(), policyReview(), ['author' => 'Yak[bot]']);
    expect($decision['reasons'])->toBe(['Yak opened this pull request.']);
});

it('holds without a concerns file', function () {
    fakeYakFiles([]);
    expect(policyEvaluate(policyFiles(), policyReview())['reasons'])
        ->toBe(['No concerns file (risk-profile.yml version 2) on the default branch.']);
});

it('holds without required status checks, including when the lookup throws', function (bool $throws) {
    $this->github->shouldReceive('hasRequiredStatusChecks')->andReturnUsing(fn () => $throws ? throw new RuntimeException('403') : false);
    $decision = policyEvaluate(policyFiles(), policyReview());
    expect($decision['reasons'])->toBe(['Branch protection on main has no required status checks.'])
        ->and($decision['observed']['required_checks_present'])->toBeFalse();
})->with([false, true]);

it('holds a floor path and never calls the classifier', function () {
    Ai::fakeAgent(ConcernClassifier::class, [policyAnswer()]);
    $decision = policyEvaluate(policyFiles('.env'), policyReview());
    expect($decision['reasons'])->toBe(['Always needs a human: .env'])->and($decision['classification'])->toBeNull();
    ConcernClassifier::assertNeverPrompted();
});

it('holds a file the review path excludes skip, and never calls the classifier', function () {
    Ai::fakeAgent(ConcernClassifier::class, [policyAnswer()]);
    $this->livePullRequest['changed_files'] = 2;
    $decision = policyEvaluate(policyFiles('app/Thing.php', 'public/build/app.js'), policyReview());
    expect($decision['reasons'])->toBe(['Not reviewed by Yak: public/build/app.js'])->and($decision['classification'])->toBeNull();
    ConcernClassifier::assertNeverPrompted();
});

it('holds a file the repository review path excludes skip', function () {
    $this->repo->update(['pr_review_path_excludes' => ['generated/**']]);
    Ai::fakeAgent(ConcernClassifier::class, [policyAnswer()]);
    expect(policyEvaluate(policyFiles('generated/schema.php'), policyReview())['reasons'])->toBe(['Not reviewed by Yak: generated/schema.php']);
});

it('holds an unclean review', function (ParsedReview $review, array $metadata, string $reason) {
    Ai::fakeAgent(ConcernClassifier::class, [policyAnswer()]);
    expect(policyEvaluate(policyFiles(), $review, $metadata)['reasons'])->toContain($reason);
    ConcernClassifier::assertNeverPrompted();
})->with([
    'findings' => [fn () => policyReview([new ReviewFinding('app/Thing.php', 1, 'should_fix', 'Tests', 'Missing.')]), [], 'Review contains findings.'],
    'verdict' => [fn () => policyReview(verdict: 'Request changes'), [], 'Review verdict is not a clean approval.'],
    'scope' => [fn () => policyReview(), ['review_scope' => 'incremental'], 'Approval requires a full review; request another review.'],
    'outstanding' => [fn () => policyReview(priorFindings: [new ParsedPriorFinding(1, 'still_outstanding', '')]), [], 'An earlier finding is still open.'],
    'untouched' => [fn () => policyReview(priorFindings: [new ParsedPriorFinding(1, 'untouched', '')]), [], 'An earlier finding is still open.'],
]);

it('approves a second round whose prior findings are fixed or withdrawn', function () {
    fakePolicyClassifier();
    $review = policyReview(priorFindings: [new ParsedPriorFinding(1, 'fixed', ''), new ParsedPriorFinding(2, 'withdrawn', '')]);
    expect(policyEvaluate(policyFiles(), $review)['candidate'])->toBe('APPROVE');
});

it('holds when the concern check is uncertain or failed', function () {
    Ai::fakeAgent(ConcernClassifier::class, [['touched_concerns' => [], 'change_kind' => 'no_runtime_change', 'is_uncertain' => true], policyAnswer()]);
    expect(policyEvaluate(policyFiles(), policyReview())['reasons'])->toBe(['Concern check is uncertain.']);

    $files = [['filename' => 'a.php', 'status' => 'modified']];
    expect(policyEvaluate($files, policyReview())['reasons'])->toBe(['Diff for a.php is not available.']);
});

it('approves a clean behavior change at level 5, only as a comment in shadow', function () {
    fakePolicyClassifier('behavior_change');
    $decision = policyEvaluate(policyFiles(), policyReview());
    expect($decision['candidate'])->toBe('APPROVE')->and($decision['event'])->toBe('COMMENT')
        ->and($decision['classification']['change_kind'])->toBe('behavior_change')
        ->and($decision['level'])->toBe(5)->and($decision['concerns_version'])->not->toBeNull();

    $this->repo->setApproval(5, 'enforce', null);
    fakePolicyClassifier('behavior_change');
    expect(policyEvaluate(policyFiles(), policyReview())['event'])->toBe('APPROVE');
});

it('allows behavior changes at level 4 only with a test file', function () {
    $this->repo->setApproval(4, 'enforce', null);
    fakePolicyClassifier('behavior_change');
    $decision = policyEvaluate(policyFiles(), policyReview());
    expect($decision['event'])->toBe('COMMENT')->and($decision['reasons'])->toBe(['DEFCON 4 does not cover behavior_change changes.']);

    fakePolicyClassifier('behavior_change');
    $this->livePullRequest['changed_files'] = 2;
    $decision = policyEvaluate(policyFiles('app/Thing.php', 'tests/Feature/XTest.php'), policyReview());
    expect($decision['event'])->toBe('APPROVE')->and($decision['observed']['has_test_change'])->toBeTrue();
});

it('does not count a removed test file as a test change', function () {
    $this->repo->setApproval(4, 'enforce', null);
    fakePolicyClassifier('behavior_change');
    $files = [...policyFiles(), ['filename' => 'tests/Feature/XTest.php', 'status' => 'removed', 'patch' => '-x']];
    expect(policyEvaluate($files, policyReview())['event'])->toBe('COMMENT');
});

it('fits the change kind to levels 2 and 3', function () {
    $this->repo->setApproval(3, 'enforce', null);
    fakePolicyClassifier('presentation_or_internal');
    expect(policyEvaluate(policyFiles(), policyReview())['event'])->toBe('APPROVE');
    fakePolicyClassifier('behavior_change');
    expect(policyEvaluate(policyFiles(), policyReview())['reasons'])->toBe(['DEFCON 3 does not cover behavior_change changes.']);

    $this->repo->setApproval(2, 'enforce', null);
    fakePolicyClassifier('presentation_or_internal');
    expect(policyEvaluate(policyFiles(), policyReview())['reasons'])->toBe(['DEFCON 2 does not cover presentation_or_internal changes.']);
    fakePolicyClassifier('no_runtime_change');
    expect(policyEvaluate(policyFiles(), policyReview())['event'])->toBe('APPROVE');
});

it('holds a touched always_human concern, and a when_tested one without a test change', function () {
    $this->repo->setApproval(5, 'enforce', null);
    fakePolicyClassifier('behavior_change', [['name' => BILLING, 'evidence' => 'charges']]);
    expect(policyEvaluate(policyFiles('tests/Feature/XTest.php'), policyReview())['reasons'])->toBe(['Always needs a human: ' . BILLING]);

    fakePolicyClassifier('behavior_change', [['name' => METERING, 'evidence' => 'meter']]);
    expect(policyEvaluate(policyFiles(), policyReview())['reasons'])->toBe(['Touches ' . METERING . ' without a test change.']);

    fakePolicyClassifier('behavior_change', [['name' => METERING, 'evidence' => 'meter']]);
    $this->livePullRequest['changed_files'] = 2;
    expect(policyEvaluate(policyFiles('app/Thing.php', 'tests/Feature/XTest.php'), policyReview())['event'])->toBe('APPROVE');
});

it('requests changes for a visible must_fix finding in enforce only', function () {
    $review = policyReview([new ReviewFinding('app/Thing.php', 1, 'must_fix', 'Correctness', 'Broken.')]);
    $this->repo->setApproval(5, 'enforce', null);
    expect(policyEvaluate(policyFiles(), $review)['event'])->toBe('REQUEST_CHANGES');
    expect(policyEvaluate(policyFiles(), $review, ['author' => 'yak[bot]'])['event'])->toBe('COMMENT');

    $this->repo->setApproval(5, 'shadow', null);
    $decision = policyEvaluate(policyFiles(), $review);
    expect($decision['candidate'])->toBe('REQUEST_CHANGES')->and($decision['event'])->toBe('COMMENT');
});

it('holds when the pull request author is unknown', function (bool $keyPresent) {
    Ai::fakeAgent(ConcernClassifier::class, [policyAnswer()]);
    $metadata = ['pr_number' => 42, 'head_sha' => 'head', 'base_ref' => 'main', 'review_scope' => 'full'];
    if ($keyPresent) {
        $metadata['author'] = '';
    }
    $decision = app(ReviewApprovalPolicy::class)->evaluate($this->repo, policyReview(), $metadata, policyFiles());
    expect($decision['candidate'])->not->toBe('APPROVE')->and($decision['reasons'])->toBe(['Pull request author is unknown.']);
    ConcernClassifier::assertNeverPrompted();
})->with([true, false]);

it('holds when the live pull request no longer matches the review', function (callable $change, string $reason) {
    Ai::fakeAgent(ConcernClassifier::class, [policyAnswer()]);
    $change($this);
    $decision = policyEvaluate(policyFiles(), policyReview());
    expect($decision['candidate'])->not->toBe('APPROVE')->and($decision['reasons'])->toBe([$reason]);
    ConcernClassifier::assertNeverPrompted();
})->with([
    'moved head' => [fn ($t) => $t->livePullRequest['head']['sha'] = 'new', 'Pull request changed since the review; Yak will review again.'],
    'moved base ref' => [fn ($t) => $t->livePullRequest['base']['ref'] = 'dev', 'Pull request changed since the review; Yak will review again.'],
    'closed' => [fn ($t) => $t->livePullRequest['state'] = 'closed', 'Pull request is not open, is a draft, has auto-merge on, or comes from a fork.'],
    'draft' => [fn ($t) => $t->livePullRequest['draft'] = true, 'Pull request is not open, is a draft, has auto-merge on, or comes from a fork.'],
    'auto merge' => [fn ($t) => $t->livePullRequest['auto_merge'] = ['enabled_by' => []], 'Pull request is not open, is a draft, has auto-merge on, or comes from a fork.'],
    'fork' => [fn ($t) => $t->livePullRequest['head']['repo']['full_name'] = 'x/api', 'Pull request is not open, is a draft, has auto-merge on, or comes from a fork.'],
    'file count' => [fn ($t) => $t->livePullRequest['changed_files'] = 2, 'Changed file list is incomplete.'],
    'not found' => [fn ($t) => $t->github->shouldReceive('getPullRequestOrFail')->andReturn(null), 'Could not verify the pull request on GitHub.'],
    'throws' => [fn ($t) => $t->github->shouldReceive('getPullRequestOrFail')->andThrow(new RuntimeException('500')), 'Could not verify the pull request on GitHub.'],
    'live author is the bot' => [fn ($t) => $t->livePullRequest['user']['login'] = 'yak[bot]', 'Yak opened this pull request.'],
    'live author empty' => [fn ($t) => $t->livePullRequest['user'] = [], 'Pull request author is unknown.'],
]);

function earlierComment(?string $status, int $commentId = 900): void
{
    $review = PrReview::factory()->create(['repo' => 'acme/api', 'pr_number' => 42, 'pr_url' => POLICY_PR_URL]);
    PrReviewComment::factory()->create(['pr_review_id' => $review->id, 'github_comment_id' => $commentId, 'resolution_status' => $status]);
}

function fakeThreads(bool $isResolved, int $commentId = 900): void
{
    test()->github->shouldReceive('listReviewThreads')->andReturn([['is_resolved' => $isResolved, 'comment_database_ids' => [$commentId]]]);
}

it('holds while an earlier finding the reviewer omitted is still open', function (?string $status) {
    earlierComment($status);
    fakeThreads(false);
    Ai::fakeAgent(ConcernClassifier::class, [policyAnswer()]);

    expect(policyEvaluate(policyFiles(), policyReview())['reasons'])->toContain('An earlier Yak finding is still open.');
    ConcernClassifier::assertNeverPrompted();
})->with([null, 'untouched', 'still_outstanding', 'fixed']);

it('holds a sticky still_outstanding finding even when its thread is resolved', function () {
    earlierComment('still_outstanding');
    fakeThreads(true);

    expect(policyEvaluate(policyFiles(), policyReview())['reasons'])->toContain('An earlier Yak finding is still open.');
});

it('holds when the earlier finding threads cannot be fetched', function () {
    earlierComment('fixed');
    $this->github->shouldReceive('listReviewThreads')->andThrow(new RuntimeException('502'));

    expect(policyEvaluate(policyFiles(), policyReview())['reasons'])->toBe(['Could not verify earlier findings on GitHub.']);
});

it('approves when every earlier finding is closed and its thread resolved', function () {
    earlierComment('fixed', 900);
    earlierComment('still_outstanding', 901);
    earlierComment(null, 902);
    $this->github->shouldReceive('listReviewThreads')->andReturn([
        ['is_resolved' => true, 'comment_database_ids' => [900]],
        ['is_resolved' => true, 'comment_database_ids' => [901]],
        ['is_resolved' => true, 'comment_database_ids' => [902]],
    ]);
    fakePolicyClassifier();
    $review = policyReview(priorFindings: [new ParsedPriorFinding(901, 'fixed', '')]);

    expect(policyEvaluate(policyFiles(), $review)['candidate'])->toBe('APPROVE');
});
