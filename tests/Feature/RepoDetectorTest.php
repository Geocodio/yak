<?php

use App\Ai\Agents\RepoRoutingAgent;
use App\DataTransferObjects\TaskDescription;
use App\Models\Repository;
use App\Services\RepoDetector;
use Laravel\Ai\Ai;

beforeEach(function (): void {
    $this->detector = new RepoDetector;
});

test('explicit mention resolves repo via input driver repository field', function (): void {
    Repository::factory()->create(['slug' => 'my-app']);

    $description = new TaskDescription(
        title: 'Fix bug',
        body: 'Fix the login bug',
        channel: 'slack',
        externalId: 'SLACK-20260411-1',
        repository: 'my-app',
    );

    $result = $this->detector->detect($description);

    expect($result->resolved)->toBeTrue()
        ->and($result->needsClarification)->toBeFalse()
        ->and($result->repositories)->toHaveCount(1)
        ->and($result->repositories->first()->slug)->toBe('my-app');
});

test('explicit mention via --repo= flag in body', function (): void {
    Repository::factory()->create(['slug' => 'api-service']);

    $description = new TaskDescription(
        title: 'Fix bug',
        body: 'Fix the login bug --repo=api-service',
        channel: 'slack',
        externalId: 'SLACK-20260411-1',
    );

    $result = $this->detector->detect($description);

    expect($result->resolved)->toBeTrue()
        ->and($result->repositories->first()->slug)->toBe('api-service');
});

test('sentry project mapping resolves repo', function (): void {
    Repository::factory()->withSentry()->create([
        'slug' => 'backend',
        'sentry_project' => 'backend-prod',
    ]);

    $description = new TaskDescription(
        title: 'TypeError in handler',
        body: 'Error details',
        channel: 'sentry',
        externalId: 'SENTRY-123',
        metadata: ['sentry_project' => 'backend-prod'],
    );

    $result = $this->detector->detect($description);

    expect($result->resolved)->toBeTrue()
        ->and($result->repositories->first()->slug)->toBe('backend');
});

test('explicit mention takes priority over sentry mapping', function (): void {
    Repository::factory()->withSentry()->create([
        'slug' => 'backend',
        'sentry_project' => 'backend-prod',
    ]);
    Repository::factory()->create(['slug' => 'frontend']);

    $description = new TaskDescription(
        title: 'Fix bug',
        body: 'Fix the bug',
        channel: 'sentry',
        externalId: 'SENTRY-456',
        repository: 'frontend',
        metadata: ['sentry_project' => 'backend-prod'],
    );

    $result = $this->detector->detect($description);

    expect($result->resolved)->toBeTrue()
        ->and($result->repositories->first()->slug)->toBe('frontend');
});

test('default repo fallback for non-slack channels', function (): void {
    Repository::factory()->create(['slug' => 'app-one']);
    Repository::factory()->default()->create(['slug' => 'app-two']);

    $description = new TaskDescription(
        title: 'Fix issue',
        body: 'Fix issue in Linear',
        channel: 'linear',
        externalId: 'LIN-123',
    );

    $result = $this->detector->detect($description);

    expect($result->resolved)->toBeTrue()
        ->and($result->repositories->first()->slug)->toBe('app-two');
});

test('inactive repo is skipped for explicit mention', function (): void {
    Repository::factory()->inactive()->create(['slug' => 'old-app']);
    Repository::factory()->default()->create(['slug' => 'active-app']);

    $description = new TaskDescription(
        title: 'Fix bug',
        body: 'Fix the bug',
        channel: 'linear',
        externalId: 'LIN-456',
        repository: 'old-app',
    );

    $result = $this->detector->detect($description);

    expect($result->resolved)->toBeTrue()
        ->and($result->repositories->first()->slug)->toBe('active-app');
});

test('inactive repo is skipped for sentry mapping', function (): void {
    Repository::factory()->inactive()->withSentry()->create([
        'slug' => 'archived',
        'sentry_project' => 'archived-prod',
    ]);

    $description = new TaskDescription(
        title: 'Error',
        body: 'Error details',
        channel: 'sentry',
        externalId: 'SENTRY-789',
        metadata: ['sentry_project' => 'archived-prod'],
    );

    $result = $this->detector->detect($description);

    expect($result->resolved)->toBeFalse();
});

test('single active repo is always used without clarification', function (): void {
    Repository::factory()->create(['slug' => 'only-repo']);

    $description = new TaskDescription(
        title: 'Fix something',
        body: 'Fix something in the app',
        channel: 'slack',
        externalId: 'SLACK-20260411-1',
    );

    $result = $this->detector->detect($description);

    expect($result->resolved)->toBeTrue()
        ->and($result->needsClarification)->toBeFalse()
        ->and($result->repositories->first()->slug)->toBe('only-repo');
});

test('multi-repo with natural language routes via Haiku before clarification', function (): void {
    config(['ai.providers.anthropic.key' => 'sk-ant-test']);
    Ai::fakeAgent(RepoRoutingAgent::class, ['acme/deployer']);

    Repository::factory()->create(['slug' => 'acme/api']);
    Repository::factory()->create(['slug' => 'acme/deployer']);
    Repository::factory()->create(['slug' => 'acme/web']);

    $description = new TaskDescription(
        title: 'Add confetti',
        body: 'in the deployer tool. I want confetti to animate all over the screen after you trigger a deployment.',
        channel: 'slack',
        externalId: 'SLACK-20260414-1',
    );

    $result = $this->detector->detect($description);

    expect($result->resolved)->toBeTrue()
        ->and($result->needsClarification)->toBeFalse()
        ->and($result->repositories->first()->slug)->toBe('acme/deployer');
});

test('slack low-confidence triggers clarification with multiple active repos', function (): void {
    Repository::factory()->create(['slug' => 'app']);
    Repository::factory()->create(['slug' => 'api']);

    $description = new TaskDescription(
        title: 'Fix something',
        body: 'Fix the thing',
        channel: 'slack',
        externalId: 'SLACK-20260411-1',
    );

    $result = $this->detector->detect($description);

    expect($result->resolved)->toBeFalse()
        ->and($result->needsClarification)->toBeTrue()
        ->and($result->options)->toHaveCount(2);
});

test('multi-repo request detects multiple repos from text', function (): void {
    Repository::factory()->create(['slug' => 'app']);
    Repository::factory()->create(['slug' => 'api']);

    $description = new TaskDescription(
        title: 'Audit cron jobs',
        body: 'audit cron jobs across app and api',
        channel: 'slack',
        externalId: 'SLACK-20260411-1',
    );

    $result = $this->detector->detect($description);

    expect($result->resolved)->toBeTrue()
        ->and($result->isMultiRepo())->toBeTrue()
        ->and($result->repositories)->toHaveCount(2);

    $slugs = $result->repositories->pluck('slug')->sort()->values()->all();
    expect($slugs)->toBe(['api', 'app']);
});

test('unresolved when no active repos exist', function (): void {
    $description = new TaskDescription(
        title: 'Fix bug',
        body: 'Fix the bug',
        channel: 'slack',
        externalId: 'SLACK-20260411-1',
    );

    $result = $this->detector->detect($description);

    expect($result->resolved)->toBeFalse()
        ->and($result->needsClarification)->toBeFalse();
});

test('detection priority: explicit over sentry over default', function (): void {
    Repository::factory()->create(['slug' => 'explicit']);
    Repository::factory()->withSentry()->create([
        'slug' => 'sentry-repo',
        'sentry_project' => 'my-sentry',
    ]);
    Repository::factory()->default()->create(['slug' => 'default-repo']);

    $description = new TaskDescription(
        title: 'Fix bug',
        body: 'Fix the bug',
        channel: 'linear',
        externalId: 'LIN-999',
        repository: 'explicit',
        metadata: ['sentry_project' => 'my-sentry'],
    );

    $result = $this->detector->detect($description);

    expect($result->repositories->first()->slug)->toBe('explicit');
});

test('isSingleRepo helper returns true for single resolved repo', function (): void {
    Repository::factory()->create(['slug' => 'solo']);

    $description = new TaskDescription(
        title: 'Fix',
        body: 'Fix it',
        channel: 'slack',
        externalId: 'SLACK-1',
    );

    $result = $this->detector->detect($description);

    expect($result->isSingleRepo())->toBeTrue()
        ->and($result->isMultiRepo())->toBeFalse();
});
