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
        'state' => 'open', 'draft' => false, 'auto_merge' => null, 'changed_files' => 1, 'commits' => 1,
        'head' => ['sha' => 'head', 'repo' => ['full_name' => 'acme/api']],
        'base' => ['ref' => 'main', 'repo' => ['full_name' => 'acme/api']], 'user' => ['login' => 'alice'],
    ];
    $this->github->shouldReceive('getPullRequestOrFail')->andReturnUsing(fn () => $this->livePullRequest)->byDefault();
    $this->github->shouldReceive('hasRequiredStatusChecks')->andReturn(true)->byDefault();
    $this->pullRequestCommits = [['author' => ['login' => 'alice'], 'commit' => ['author' => ['email' => 'alice@example.com'], 'committer' => ['email' => 'noreply@github.com'], 'message' => 'Split warnings']]];
    $this->github->shouldReceive('listPullRequestCommits')->andReturnUsing(fn () => $this->pullRequestCommits)->byDefault();
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

it('holds a pull request with a commit Yak wrote or co-authored', function (array $commit) {
    config(['yak.git_user_email' => 'yak@noreply.github.com']);
    $this->livePullRequest['commits'] = 2;
    $this->pullRequestCommits[] = array_replace_recursive(['author' => ['login' => 'alice'], 'commit' => ['author' => ['email' => 'alice@example.com'], 'committer' => ['email' => 'alice@example.com'], 'message' => 'Fix it']], $commit);
    fakePolicyClassifier();

    expect(policyEvaluate(policyFiles(), policyReview())['reasons'])->toBe(['Yak wrote commits on this pull request.']);
})->with([
    'bot account' => [['author' => ['login' => 'Yak[bot]']]],
    'author email' => [['commit' => ['author' => ['email' => 'Yak@noreply.github.com']]]],
    'committer email' => [['commit' => ['committer' => ['email' => 'yak@noreply.github.com']]]],
    'co-author trailer' => [['commit' => ['message' => "Fix it\n\nCo-authored-by: Yak <yak@noreply.github.com>"]]],
]);

it('holds when not every pull request commit could be read', function (callable $change) {
    $change($this);
    fakePolicyClassifier();

    expect(policyEvaluate(policyFiles(), policyReview())['reasons'])->toBe(['Could not verify the pull request commits.']);
})->with([
    'listing throws' => [fn ($test) => $test->github->shouldReceive('listPullRequestCommits')->andThrow(new RuntimeException('GitHub down'))],
    'error returns an empty list' => [fn ($test) => $test->pullRequestCommits = []],
    'listing stops at 250' => [fn ($test) => $test->livePullRequest['commits'] = 300],
    'commit count missing' => [function ($test) {
        unset($test->livePullRequest['commits']);
    }],
]);

it('holds without a concerns file', function () {
    fakeYakFiles([]);
    expect(policyEvaluate(policyFiles(), policyReview())['reasons'])
        ->toBe(['No concerns file (risk-profile.yml version 2) on the default branch.']);
});

it('holds without required status checks', function () {
    $this->github->shouldReceive('hasRequiredStatusChecks')->andReturn(false);
    $decision = policyEvaluate(policyFiles(), policyReview());
    expect($decision['reasons'])->toBe(['Branch protection on main has no required status checks.'])
        ->and($decision['observed']['required_checks_present'])->toBeFalse();
});

it('holds with a distinct reason when branch protection cannot be read', function () {
    $this->github->shouldReceive('hasRequiredStatusChecks')->andThrow(new RuntimeException('403'));
    $decision = policyEvaluate(policyFiles(), policyReview());
    expect($decision['reasons'])->toBe(['Could not read branch protection on main.'])
        ->and($decision['observed']['required_checks_present'])->toBeNull();
});

it('never asks about stale approval dismissal', function () {
    fakePolicyClassifier();
    $decision = policyEvaluate(policyFiles(), policyReview());
    expect(implode(' ', $decision['reasons']))->not->toContain('stale')
        ->and($decision['observed'])->not->toHaveKey('dismisses_stale_reviews');
});

it('holds Yak config, CI and migrations, including removed and renamed ones', function (array $file) {
    Ai::fakeAgent(ConcernClassifier::class, [policyAnswer()]);
    $reasons = policyEvaluate([$file + ['patch' => '+x']], policyReview())['reasons'];
    expect($reasons)->not->toBe([])->and($reasons[0])->toStartWith('Always needs a human: ');
    ConcernClassifier::assertNeverPrompted();
})->with([
    'yak config' => [['filename' => '.yak/risk-profile.yml', 'status' => 'modified']],
    'migration' => [['filename' => 'database/migrations/2026_01_01_000000_x.php', 'status' => 'added']],
    'workflow' => [['filename' => '.github/workflows/ci.yml', 'status' => 'modified']],
    'removed' => [['filename' => '.github/workflows/old.yml', 'status' => 'removed']],
    'renamed away' => [['filename' => 'app/Other.php', 'previous_filename' => 'config/auth.php', 'status' => 'renamed']],
]);

it('lets business and dependency paths through the floor', function (string $path) {
    fakePolicyClassifier();
    expect(policyEvaluate(policyFiles($path), policyReview())['reasons'])->toBe([]);
})->with(['config/billing.php', 'composer.json', 'routes/web.php', 'app/Support/InvoicePolicy.php']);

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

it('fills a patch GitHub left out from the compare diff, and holds a file with no text hunks', function () {
    $this->livePullRequest['base']['sha'] = 'base';
    $this->livePullRequest['changed_files'] = 2;
    $this->github->shouldReceive('getCompareDiff')->with(Mockery::any(), 'acme/api', 'base', 'head')->andReturn(implode("\n", [
        'diff --git a/tests/.pest/shards.json b/tests/.pest/shards.json',
        'index 1111111..2222222 100644',
        '--- a/tests/.pest/shards.json',
        '+++ b/tests/.pest/shards.json',
        '@@ -1 +1 @@',
        '-{"a": 1}',
        '+{"a": 2}',
        'diff --git a/logo.png b/logo.png',
        'Binary files a/logo.png and b/logo.png differ',
        '',
    ]));
    $files = [['filename' => 'tests/.pest/shards.json', 'status' => 'modified'], ['filename' => 'app/Thing.php', 'status' => 'modified', 'patch' => '+x']];

    fakePolicyClassifier('no_runtime_change');
    expect(policyEvaluate($files, policyReview())['candidate'])->toBe('APPROVE');
    ConcernClassifier::assertPrompted(fn ($prompt) => $prompt->contains("@@ -1 +1 @@\n-{\"a\": 1}\n+{\"a\": 2}"));

    $files[1] = ['filename' => 'logo.png', 'status' => 'modified'];
    expect(policyEvaluate($files, policyReview())['reasons'])->toBe(['Diff for logo.png is not available.']);
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
    'closed' => [fn ($t) => $t->livePullRequest['state'] = 'closed', 'Pull request is not open.'],
    'draft' => [fn ($t) => $t->livePullRequest['draft'] = true, 'Pull request is a draft.'],
    'auto merge' => [fn ($t) => $t->livePullRequest['auto_merge'] = ['enabled_by' => []], 'Pull request has auto-merge on.'],
    'fork' => [fn ($t) => $t->livePullRequest['head']['repo']['full_name'] = 'x/api', 'Pull request comes from a fork.'],
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
})->with([null, 'untouched', 'still_outstanding']);

it('holds an unresolved-by-Yak finding even when its GitHub thread is resolved', function (?string $status) {
    earlierComment($status);
    $this->github->shouldReceive('listReviewThreads')->andReturn([['is_resolved' => true, 'comment_database_ids' => [900]]]);
    Ai::fakeAgent(ConcernClassifier::class, [policyAnswer()]);

    expect(policyEvaluate(policyFiles(), policyReview())['reasons'])->toContain('An earlier Yak finding is still open.');
    ConcernClassifier::assertNeverPrompted();
})->with([null, 'untouched', 'still_outstanding']);

it('holds an earlier finding the reviewer reports as still outstanding', function () {
    earlierComment('fixed', 900);
    earlierComment(null, 901);
    $review = policyReview(priorFindings: [new ParsedPriorFinding(901, 'still_outstanding', '')]);

    expect(policyEvaluate(policyFiles(), $review)['reasons'])->toContain('An earlier finding is still open.');
});

it('approves when Yak recorded every earlier finding as closed or reports it closed now, resolved threads or not', function () {
    earlierComment('fixed', 900);
    earlierComment('withdrawn', 901);
    earlierComment('still_outstanding', 902);
    earlierComment(null, 903);
    fakePolicyClassifier();
    $review = policyReview(priorFindings: [new ParsedPriorFinding(902, 'fixed', ''), new ParsedPriorFinding(903, 'withdrawn', '')]);

    expect(policyEvaluate(policyFiles(), $review)['candidate'])->toBe('APPROVE');
});
