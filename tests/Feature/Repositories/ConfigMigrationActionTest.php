<?php

use App\Actions\OpenConfigMigrationPullRequest;
use App\Models\Repository;
use App\Models\User;

function migrationBody(bool $riskProfileUnapproved, array $checksLostAppPin, ?string $riskProfileDate = '2026-09-21'): string
{
    return view('pull-requests.config-migration', [
        'repository' => Repository::factory()->make(['name' => 'geocodio']),
        'files' => ['.yak/config.yml' => 'Description, CI system', '.yak/risk-profile.yml' => 'Risk profile draft'],
        'riskProfileUnapproved' => $riskProfileUnapproved,
        'riskProfileDate' => $riskProfileDate,
        'checksLostAppPin' => $checksLostAppPin,
    ])->render();
}

it('renders the C5 sections', function () {
    $body = migrationBody(false, []);

    expect($body)->toContain('## Summary')
        ->toContain('| `.yak/config.yml` | Description, CI system |')
        ->toContain('## After merging')
        ->toContain('`yak / config`')
        ->not->toContain('never approved')
        ->not->toContain('match by name');
});

it('warns about the risk profile only when it was never approved', function () {
    expect(migrationBody(true, []))->toContain('The risk profile was never approved')->toContain('2026-09-21');
});

it('names the checks that lose their app pin only when there are some', function () {
    expect(migrationBody(false, ['tests', 'phpstan']))->toContain('Required checks match by name')->toContain('`tests` and `phpstan`');
});

it('refuses to migrate a repository that already has .yak/ files', function () {
    $repository = Repository::factory()->create(['slug' => 'api']);
    fakeYakFiles(['config.yml' => "version: 1\n"]);

    app(OpenConfigMigrationPullRequest::class)->handle($repository);
})->throws(RuntimeException::class, 'api already has .yak/ files');

it('flashes the opened pull request', function () {
    $repository = Repository::factory()->create();
    $this->mock(OpenConfigMigrationPullRequest::class)->shouldReceive('handle')->once()
        ->andReturn(['number' => 12, 'url' => 'https://github.com/acme/api/pull/12', 'created' => true]);

    $this->actingAs(User::factory()->create())->post(route('repos.config.migrate', $repository))
        ->assertRedirect()
        ->assertSessionHas('success', 'Opened #12 https://github.com/acme/api/pull/12');
});

it('flashes an error when the action throws', function () {
    $repository = Repository::factory()->create();
    $this->mock(OpenConfigMigrationPullRequest::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('GitHub App is not configured'));

    $this->actingAs(User::factory()->create())->post(route('repos.config.migrate', $repository))
        ->assertRedirect()
        ->assertSessionHas('error', 'GitHub App is not configured');
});

it('refuses to migrate and writes nothing when .yak/ could not be read', function () {
    Http::fake();
    $repository = Repository::factory()->create(['slug' => 'api']);
    fakeUnreachableYakRead(['api']);

    expect(fn () => app(OpenConfigMigrationPullRequest::class)->handle($repository))
        ->toThrow(RuntimeException::class, 'Could not read .yak/ for api from GitHub');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'git/blobs'));
});
