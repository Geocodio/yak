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
    Ai::fakeAgent(RepoRoutingAgent::class, ['acme/deployer']);

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

test('returns null when agent returns UNKNOWN', function (): void {
    Ai::fakeAgent(RepoRoutingAgent::class, ['UNKNOWN']);

    $repos = collect([
        Repository::factory()->create(['slug' => 'repo-a']),
        Repository::factory()->create(['slug' => 'repo-b']),
    ]);

    $result = (new RepoRouter)->route('something ambiguous', $repos);

    expect($result)->toBeNull();
});

test('returns null when agent returns a slug not in the list', function (): void {
    Ai::fakeAgent(RepoRoutingAgent::class, ['some-other-repo']);

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

        return 'my-repo';
    });

    $repos = collect([
        Repository::factory()->create([
            'slug' => 'my-repo',
            'description' => 'Customer signup and billing service',
            'notes' => 'Uses Stripe webhooks',
        ]),
    ]);

    (new RepoRouter)->route('fix the signup flow', $repos);

    expect($captured)->not->toBeNull();
    expect((string) $captured)->toContain('my-repo');
    expect((string) $captured)->toContain('Customer signup and billing service');
    expect((string) $captured)->toContain('Uses Stripe webhooks');
});
