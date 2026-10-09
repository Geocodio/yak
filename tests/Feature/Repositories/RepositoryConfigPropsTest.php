<?php

use App\DataTransferObjects\ConfigFile;
use App\DataTransferObjects\ConfigSnapshot;
use App\Models\Repository;
use App\Models\User;
use App\Services\RepositoryConfig;
use Carbon\CarbonImmutable;
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
