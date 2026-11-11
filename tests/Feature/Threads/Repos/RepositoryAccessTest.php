<?php

use App\Models\Repository;
use App\Models\User;
use App\Services\Threads\Repos\RepositoryAccess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '999');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 42);
});

it('denies a repository Yak does not know', function () {
    Http::fake();

    $result = app(RepositoryAccess::class)->check('acme/docs');

    expect($result->allowed)->toBeFalse()
        ->and($result->reason)->toBe('Install the Yak GitHub app on acme/docs, or ask an admin.');
    Http::assertNothingSent();
});

it('denies an inactive repository', function () {
    Repository::factory()->create(['slug' => 'acme/docs', 'is_active' => false]);
    Http::fake();

    expect(app(RepositoryAccess::class)->check('acme/docs')->allowed)->toBeFalse();
});

it('denies a repository the installation cannot reach', function () {
    Repository::factory()->create(['slug' => 'acme/docs']);
    Http::fake([
        'api.github.com/app/installations/42/access_tokens' => Http::response(['token' => 'ghs_x', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        'api.github.com/repos/acme/docs' => Http::response([], 404),
    ]);

    $result = app(RepositoryAccess::class)->check('acme/docs');

    expect($result->allowed)->toBeFalse()
        ->and($result->reason)->toContain('acme/docs');
});

it('allows a known repository the installation can reach', function () {
    Repository::factory()->create(['slug' => 'acme/docs']);
    Http::fake([
        'api.github.com/app/installations/42/access_tokens' => Http::response(['token' => 'ghs_x', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        'api.github.com/repos/acme/docs' => Http::response(['description' => 'Docs', 'topics' => []]),
    ]);

    $result = app(RepositoryAccess::class)->check('acme/docs');

    expect($result->allowed)->toBeTrue()->and($result->reason)->toBeNull();
});

it('answers an unknown and an unreachable slug with the same body and status', function () {
    Repository::factory()->create(['slug' => 'acme/docs']);
    Http::fake([
        'api.github.com/app/installations/42/access_tokens' => Http::response(['token' => 'ghs_x', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        'api.github.com/repos/acme/docs' => Http::response([], 404),
    ]);
    $user = User::factory()->create();

    $unreachable = $this->actingAs($user)->getJson('/threads/new/repository-access?repo=acme/docs');
    Http::assertSentCount(2);
    $unknown = $this->actingAs($user)->getJson('/threads/new/repository-access?repo=acme/ghost');

    Http::assertSentCount(2);
    expect($unknown->status())->toBe($unreachable->status())
        ->and($unknown->json('allowed'))->toBeFalse()
        ->and(array_keys($unknown->json()))->toBe(array_keys($unreachable->json()));
});

it('rejects a malformed repo', function (string $repo) {
    Http::fake();

    $this->actingAs(User::factory()->create())->getJson('/threads/new/repository-access?repo=' . urlencode($repo))->assertUnprocessable();

    Http::assertNothingSent();
})->with(['no-slash', 'a/b/c', 'a/b c', '../etc/passwd/x', 'acme/']);

it('throttles repository probing', function () {
    Http::fake();
    RateLimiter::clear('x');
    $user = User::factory()->create();

    foreach (range(1, 30) as $ignored) {
        $this->actingAs($user)->getJson('/threads/new/repository-access?repo=acme/ghost')->assertOk();
    }

    $this->actingAs($user)->getJson('/threads/new/repository-access?repo=acme/ghost')->assertStatus(429);
});
