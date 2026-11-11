<?php

use App\Models\Repository;
use App\Services\Threads\Repos\RepositoryAccess;
use Illuminate\Support\Facades\Http;

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
