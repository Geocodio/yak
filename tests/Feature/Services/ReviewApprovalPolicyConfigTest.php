<?php

use App\DataTransferObjects\ParsedReview;
use App\Models\Repository;
use App\Services\RepositoryRiskProfiles;
use App\Services\ReviewApprovalPolicy;

it('matches a name-only required check against any app or a commit status', function () {
    $policy = app(ReviewApprovalPolicy::class);
    $evidence = [
        'dismiss_stale_reviews' => true, 'threads_clear' => true, 'total_count' => 1, 'status_count' => 1,
        'check_runs' => [['name' => 'tests', 'status' => 'completed', 'conclusion' => 'success', 'app' => ['id' => 555]]],
        'statuses' => [['context' => 'phpstan', 'state' => 'success', 'creator' => ['id' => 9]]],
    ];

    expect($policy->evidenceReasons($evidence, ['tests' => null, 'phpstan' => null]))->toBe([])
        ->and($policy->evidenceReasons($evidence, ['lint' => null]))->toBe(['Required CI check missing: lint'])
        ->and($policy->evidenceReasons($evidence, ['tests' => 1]))->toBe(['Required CI check missing from trusted app: tests']);
});

it('takes the review switch and approval mode from .yak/config.yml', function () {
    $repository = Repository::factory()->create(['pr_review_enabled' => false, 'is_active' => true]);
    fakeYakFiles(['config.yml' => "version: 1\nreview:\n  enabled: true\n  approval:\n    mode: shadow\n"]);

    $decision = app(ReviewApprovalPolicy::class)->evaluate(
        $repository, new ParsedReview('Summary.', 'Approve', 'Verified.', [], risk: 'low', signals: []), [], [],
    );

    expect($repository->settings()->reviewEnabled())->toBeTrue()
        ->and($decision['mode'])->toBe('shadow');
});

it('uses the .yak/ risk profile as the active profile without approval columns', function () {
    Repository::factory()->create(['slug' => 'acme/api']);
    $content = "version: 1\nareas:\n  - name: Billing\n    risk: critical\n    paths: [ \"app/Billing/**\" ]\n    symbols: []\n    rationale: Charges cards.\n    evidence: [ \"x\" ]\n";
    fakeYakFiles(['risk-profile.yml' => $content]);

    $profile = app(RepositoryRiskProfiles::class)->active('acme/api');

    expect($profile['source'])->toBe('file')
        ->and($profile['version'])->toBe(hash('sha256', $content));
});

it('fails closed for a database check without an app id', function () {
    $evidence = [
        'dismiss_stale_reviews' => true, 'threads_clear' => true, 'total_count' => 1, 'status_count' => 0,
        'check_runs' => [['name' => 'tests', 'status' => 'completed', 'conclusion' => 'success', 'app' => ['id' => 555]]],
        'statuses' => [],
    ];
    $policy = ['required_checks' => [['name' => 'tests', 'app_id' => null], ['name' => 'lint']]];

    expect(app(ReviewApprovalPolicy::class)->requiredCheckMap($policy))->toBe(['tests' => 0, 'lint' => 0])
        ->and(app(ReviewApprovalPolicy::class)->evidenceReasons($evidence, app(ReviewApprovalPolicy::class)->requiredCheckMap($policy)))
        ->toBe(['Required CI check missing from trusted app: tests']);
});

it('maps a file check to a name-only match', function () {
    $map = app(ReviewApprovalPolicy::class)->requiredCheckMap(['required_checks' => [['name' => 'tests', 'match_any_app' => true], ['name' => 'ci', 'app_id' => 7]]]);

    expect($map)->toBe(['tests' => null, 'ci' => 7]);
});

it('falls back to the database risk profile when risk-profile.yml is absent or invalid', function (array $files) {
    Repository::factory()->create(['slug' => 'acme/api']);
    $profiles = app(RepositoryRiskProfiles::class);
    $draft = $profiles->draft('acme/api', str_repeat('a', 40), json_encode([
        'areas' => [['name' => 'Docs', 'paths' => ['docs/**'], 'symbols' => [], 'risk' => 'low',
            'rationale' => 'Documentation only.', 'evidence' => ['docs/guide.md:1']]], 'unknowns' => [],
    ]));
    $profiles->approve('acme/api', $draft['version'], 'reviewer');
    fakeYakFiles($files);

    expect($profiles->active('acme/api')['version'])->toBe($draft['version']);
})->with([[[]], [['config.yml' => "version: 1\n"]]]);
