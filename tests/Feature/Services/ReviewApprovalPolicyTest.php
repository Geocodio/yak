<?php

use App\Ai\Agents\ConcernClassifier;
use App\Channels\GitHub\AppService;
use App\DataTransferObjects\ParsedPriorFinding;
use App\DataTransferObjects\ParsedReview;
use App\DataTransferObjects\ReviewFinding;
use App\Models\Repository;
use App\Services\ReviewApprovalPolicy;
use Laravel\Ai\Ai;

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
        array_merge(['pr_number' => 42, 'base_ref' => 'main', 'review_scope' => 'full', 'author' => 'alice'], $metadata),
        $files,
    );
}

function fakeClassifier(string $kind = 'presentation_or_internal', array $touched = []): void
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
    fakeClassifier();
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
    fakeClassifier('behavior_change');
    $decision = policyEvaluate(policyFiles(), policyReview());
    expect($decision['candidate'])->toBe('APPROVE')->and($decision['event'])->toBe('COMMENT')
        ->and($decision['classification']['change_kind'])->toBe('behavior_change')
        ->and($decision['level'])->toBe(5)->and($decision['concerns_version'])->not->toBeNull();

    $this->repo->setApproval(5, 'enforce', null);
    fakeClassifier('behavior_change');
    expect(policyEvaluate(policyFiles(), policyReview())['event'])->toBe('APPROVE');
});

it('allows behavior changes at level 4 only with a test file', function () {
    $this->repo->setApproval(4, 'enforce', null);
    fakeClassifier('behavior_change');
    $decision = policyEvaluate(policyFiles(), policyReview());
    expect($decision['event'])->toBe('COMMENT')->and($decision['reasons'])->toBe(['DEFCON 4 does not cover behavior_change changes.']);

    fakeClassifier('behavior_change');
    $decision = policyEvaluate(policyFiles('app/Thing.php', 'tests/Feature/XTest.php'), policyReview());
    expect($decision['event'])->toBe('APPROVE')->and($decision['observed']['has_test_change'])->toBeTrue();
});

it('does not count a removed test file as a test change', function () {
    $this->repo->setApproval(4, 'enforce', null);
    fakeClassifier('behavior_change');
    $files = [...policyFiles(), ['filename' => 'tests/Feature/XTest.php', 'status' => 'removed', 'patch' => '-x']];
    expect(policyEvaluate($files, policyReview())['event'])->toBe('COMMENT');
});

it('fits the change kind to levels 2 and 3', function () {
    $this->repo->setApproval(3, 'enforce', null);
    fakeClassifier('presentation_or_internal');
    expect(policyEvaluate(policyFiles(), policyReview())['event'])->toBe('APPROVE');
    fakeClassifier('behavior_change');
    expect(policyEvaluate(policyFiles(), policyReview())['reasons'])->toBe(['DEFCON 3 does not cover behavior_change changes.']);

    $this->repo->setApproval(2, 'enforce', null);
    fakeClassifier('presentation_or_internal');
    expect(policyEvaluate(policyFiles(), policyReview())['reasons'])->toBe(['DEFCON 2 does not cover presentation_or_internal changes.']);
    fakeClassifier('no_runtime_change');
    expect(policyEvaluate(policyFiles(), policyReview())['event'])->toBe('APPROVE');
});

it('holds a touched always_human concern, and a when_tested one without a test change', function () {
    $this->repo->setApproval(5, 'enforce', null);
    fakeClassifier('behavior_change', [['name' => BILLING, 'evidence' => 'charges']]);
    expect(policyEvaluate(policyFiles('tests/Feature/XTest.php'), policyReview())['reasons'])->toBe(['Always needs a human: ' . BILLING]);

    fakeClassifier('behavior_change', [['name' => METERING, 'evidence' => 'meter']]);
    expect(policyEvaluate(policyFiles(), policyReview())['reasons'])->toBe(['Touches ' . METERING . ' without a test change.']);

    fakeClassifier('behavior_change', [['name' => METERING, 'evidence' => 'meter']]);
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
    $metadata = ['pr_number' => 42, 'base_ref' => 'main', 'review_scope' => 'full'];
    if ($keyPresent) {
        $metadata['author'] = '';
    }
    $decision = app(ReviewApprovalPolicy::class)->evaluate($this->repo, policyReview(), $metadata, policyFiles());
    expect($decision['candidate'])->not->toBe('APPROVE')->and($decision['reasons'])->toBe(['Pull request author is unknown.']);
    ConcernClassifier::assertNeverPrompted();
})->with([true, false]);
