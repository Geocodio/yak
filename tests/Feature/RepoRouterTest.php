<?php

use App\Ai\Agents\RepoRoutingAgent;
use App\Models\Repository;
use App\Services\RepoRouter;
use Laravel\Ai\Ai;

beforeEach(function (): void {
    config(['ai.providers.anthropic.key' => 'sk-ant-test']);
});

test('returns null when no API key is configured', function (): void {
    config(['ai.providers.anthropic.key' => '']);

    $repos = collect([Repository::factory()->create(['slug' => 'repo-a'])]);

    $result = (new RepoRouter)->route('fix the deployer', $repos);

    expect($result)->toBeNull();
});

test('returns null when repo list is empty', function (): void {
    $result = (new RepoRouter)->route('fix something', collect());

    expect($result)->toBeNull();
});

test('resolves repo from natural language when agent returns confident match', function (): void {
    Ai::fakeAgent(RepoRoutingAgent::class, [['candidates' => [
        ['slug' => 'acme/api', 'probability' => 10],
        ['slug' => 'acme/deployer', 'probability' => 85],
    ]]]);

    $repos = collect([
        Repository::factory()->create(['slug' => 'acme/api']),
        Repository::factory()->create(['slug' => 'acme/deployer']),
    ]);

    $result = (new RepoRouter)->route(
        'In the deployer tool, I want confetti to animate after triggering a deployment',
        $repos,
    );

    expect($result)->not->toBeNull();
    expect($result->slug)->toBe('acme/deployer');
});

test('returns null when agent returns no candidates', function (): void {
    Ai::fakeAgent(RepoRoutingAgent::class, [['candidates' => []]]);

    $repos = collect([
        Repository::factory()->create(['slug' => 'repo-a']),
        Repository::factory()->create(['slug' => 'repo-b']),
    ]);

    $result = (new RepoRouter)->route('something ambiguous', $repos);

    expect($result)->toBeNull();
});

test('returns null when the top candidate is below the confidence threshold', function (): void {
    Ai::fakeAgent(RepoRoutingAgent::class, [['candidates' => [
        ['slug' => 'repo-a', 'probability' => 55],
        ['slug' => 'repo-b', 'probability' => 45],
    ]]]);

    $repos = collect([
        Repository::factory()->create(['slug' => 'repo-a']),
        Repository::factory()->create(['slug' => 'repo-b']),
    ]);

    expect((new RepoRouter)->route('something ambiguous', $repos))->toBeNull();
});

test('resolves the top candidate at exactly the confidence threshold', function (): void {
    Ai::fakeAgent(RepoRoutingAgent::class, [['candidates' => [
        ['slug' => 'repo-b', 'probability' => 30],
        ['slug' => 'repo-a', 'probability' => RepoRouter::CONFIDENCE_THRESHOLD],
    ]]]);

    $repos = collect([
        Repository::factory()->create(['slug' => 'repo-a']),
        Repository::factory()->create(['slug' => 'repo-b']),
    ]);

    expect((new RepoRouter)->route('pricing audit', $repos)?->slug)->toBe('repo-a');
});

test('returns null when agent returns a slug not in the list', function (): void {
    Ai::fakeAgent(RepoRoutingAgent::class, [['candidates' => [['slug' => 'some-other-repo', 'probability' => 90]]]]);

    $repos = collect([Repository::factory()->create(['slug' => 'repo-a'])]);

    $result = (new RepoRouter)->route('do the thing', $repos);

    expect($result)->toBeNull();
});

test('returns null when agent call fails', function (): void {
    Ai::fakeAgent(RepoRoutingAgent::class, function () {
        throw new RuntimeException('API down');
    });

    $repos = collect([Repository::factory()->create(['slug' => 'repo-a'])]);

    $result = (new RepoRouter)->route('do the thing', $repos);

    expect($result)->toBeNull();
});

test('includes repo description and notes in the routing prompt', function (): void {
    $captured = null;

    Ai::fakeAgent(RepoRoutingAgent::class, function ($prompt) use (&$captured) {
        $captured = $prompt;

        return ['candidates' => [['slug' => 'my-repo', 'probability' => 90]]];
    });

    $repos = collect([
        Repository::factory()->create([
            'slug' => 'my-repo',
            'description' => 'Customer signup and billing service',
            'notes' => 'Uses Stripe webhooks',
            'is_default' => true,
        ]),
    ]);

    (new RepoRouter)->route('fix the signup flow', $repos);

    expect($captured)->not->toBeNull();
    expect((string) $captured)->toContain('my-repo');
    expect((string) $captured)->toContain('Customer signup and billing service');
    expect((string) $captured)->toContain('Uses Stripe webhooks');
    expect((string) $captured)->toContain('my-repo (default)');
});
