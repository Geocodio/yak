<?php

use App\Models\Repository;
use App\Services\RepoRouter;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['yak.anthropic_api_key' => 'sk-ant-test']);
});

test('returns null when no API key is configured', function (): void {
    config(['yak.anthropic_api_key' => '']);

    $repos = collect([Repository::factory()->create(['slug' => 'repo-a'])]);

    $result = (new RepoRouter)->route('fix the deployer', $repos);

    expect($result)->toBeNull();
    Http::assertNothingSent();
});

test('returns null when repo list is empty', function (): void {
    $result = (new RepoRouter)->route('fix something', collect());

    expect($result)->toBeNull();
});

test('resolves repo from natural language when Haiku returns confident match', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => 'Geocodio/deployer']],
        ]),
    ]);

    $repos = collect([
        Repository::factory()->create(['slug' => 'Geocodio/geocodio']),
        Repository::factory()->create(['slug' => 'Geocodio/deployer']),
    ]);

    $result = (new RepoRouter)->route(
        'In the deployer tool, I want confetti to animate after triggering a deployment',
        $repos,
    );

    expect($result)->not->toBeNull();
    expect($result->slug)->toBe('Geocodio/deployer');
});

test('returns null when Haiku returns UNKNOWN', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => 'UNKNOWN']],
        ]),
    ]);

    $repos = collect([
        Repository::factory()->create(['slug' => 'repo-a']),
        Repository::factory()->create(['slug' => 'repo-b']),
    ]);

    $result = (new RepoRouter)->route('something ambiguous', $repos);

    expect($result)->toBeNull();
});

test('returns null when Haiku returns a slug not in the list', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => 'some-other-repo']],
        ]),
    ]);

    $repos = collect([Repository::factory()->create(['slug' => 'repo-a'])]);

    $result = (new RepoRouter)->route('do the thing', $repos);

    expect($result)->toBeNull();
});

test('returns null when API call fails', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([], 500),
    ]);

    $repos = collect([Repository::factory()->create(['slug' => 'repo-a'])]);

    $result = (new RepoRouter)->route('do the thing', $repos);

    expect($result)->toBeNull();
});

test('includes repo notes in the routing prompt', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => 'my-repo']],
        ]),
    ]);

    $repos = collect([
        Repository::factory()->create([
            'slug' => 'my-repo',
            'notes' => 'Handles customer signup and billing',
        ]),
    ]);

    (new RepoRouter)->route('fix the signup flow', $repos);

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);
        $prompt = $body['messages'][0]['content'];

        return str_contains($prompt, 'Handles customer signup and billing')
            && str_contains($prompt, 'my-repo');
    });
});
