<?php

use App\DataTransferObjects\ConfigFile;
use App\DataTransferObjects\ConfigSnapshot;
use App\Http\Resources\YakConfigData;
use App\Models\Repository;
use App\Models\User;
use App\Services\ConfigPullRequests;
use App\Services\RepositoryConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

function bindConfigSnapshot(ConfigSnapshot $snapshot): void
{
    app()->instance(RepositoryConfig::class, new class($snapshot) extends RepositoryConfig
    {
        public function __construct(private ConfigSnapshot $fixedSnapshot) {}

        public function snapshot(Repository $repository): ConfigSnapshot
        {
            return $this->fixedSnapshot;
        }
    });
}

it('defers the .yak/ config prop on the edit page', function () {
    $user = User::factory()->create();
    $repository = Repository::factory()->create(['description' => 'db']);
    fakeYakFiles(['config.yml' => "version: 1\ndescription: From file\n", 'AGENTS.md' => "# Rules\n"]);

    $this->actingAs($user)->get(route('repos.edit', $repository))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Repositories/Form')
            ->missing('yakConfig')
            ->loadDeferredProps('yak-config', fn (AssertableInertia $reload) => $reload
                ->where('yakConfig.state', 'read')
                ->where('yakConfig.values.description', 'From file')
                ->where('yakConfig.values.agentInstructions', "# Rules\n")
                ->where('yakConfig.values.ciSystem', null)
                ->where('yakConfig.files.0.valid', true)));
});

it('reports an invalid file with the last valid value still locked', function () {
    $user = User::factory()->create();
    $repository = Repository::factory()->create();
    $pullRequest = ['number' => 1482, 'title' => 'Fix config', 'url' => 'https://github.com/o/r/pull/1482'];
    $file = new ConfigFile('config.yml', ['version' => 1, 'description' => 'Last good'], "ci: nope\n", str_repeat('1', 40),
        'ci: The selected ci is invalid.', str_repeat('2', 40), $pullRequest);
    bindConfigSnapshot(new ConfigSnapshot('read', str_repeat('2', 40), CarbonImmutable::now(), null, ['config.yml' => $file]));

    $this->actingAs($user)->get(route('repos.edit', $repository))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps('yak-config', fn (AssertableInertia $reload) => $reload
                ->where('yakConfig.values.description', 'Last good')
                ->where('yakConfig.files.0.valid', false)
                ->where('yakConfig.files.0.error', 'ci: The selected ci is invalid.')
                ->where('yakConfig.files.0.errorPullRequest.number', 1482)));
});

it('reports an unreachable read', function () {
    $user = User::factory()->create();
    $repository = Repository::factory()->create();
    bindConfigSnapshot(new ConfigSnapshot('unreachable', null, null, 'GitHub returned 503', []));

    $this->actingAs($user)->get(route('repos.edit', $repository))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps('yak-config', fn (AssertableInertia $reload) => $reload
                ->where('yakConfig.state', 'unreachable')
                ->where('yakConfig.readError', 'GitHub returned 503')
                ->where('yakConfig.files', [])
                ->where('yakConfig.values.description', null)));
});

it('sends no HTTP request while rendering the edit page', function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '12345');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 99999);
    app()->forgetInstance(RepositoryConfig::class);
    Http::preventStrayRequests();
    Http::fake();

    $this->actingAs(User::factory()->create())->get(route('repos.edit', Repository::factory()->create()))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Repositories/Form')->has('repository.riskProfiles'));

    Http::assertNothingSent();
});

it('exposes the raw review approval block next to the merged policy', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['config.yml' => "version: 1\nreview:\n  approval:\n    mode: shadow\n"]);

    $values = YakConfigData::from($repository, $repository->settings()->snapshot)['values'];

    expect($values['reviewApproval'])->toBe(['mode' => 'shadow'])
        ->and($values['reviewPolicy']['max_files'])->toBe(5);
});

it('exposes the open config pull request while no .yak/ files exist', function () {
    $user = User::factory()->create();
    $repository = Repository::factory()->create();
    $this->mock(ConfigPullRequests::class)->shouldReceive('openPullRequest')->andReturn(['number' => 1490, 'title' => 'Add Yak config in .yak/', 'url' => 'https://github.com/o/r/pull/1490']);

    $this->actingAs($user)->get(route('repos.edit', $repository))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps('yak-config', fn (AssertableInertia $reload) => $reload
                ->where('yakConfig.configPullRequest.number', 1490)));
});

it('gives a null config pull request when none is open or GitHub fails', function () {
    $user = User::factory()->create();
    $repository = Repository::factory()->create();
    $this->mock(ConfigPullRequests::class)->shouldReceive('openPullRequest')->andThrow(new RuntimeException('GitHub App is not configured'));

    $this->actingAs($user)->get(route('repos.edit', $repository))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps('yak-config', fn (AssertableInertia $reload) => $reload
                ->where('yakConfig.configPullRequest', null)));
});
