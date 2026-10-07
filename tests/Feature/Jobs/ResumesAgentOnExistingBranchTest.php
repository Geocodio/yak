<?php

use App\Jobs\Concerns\ResumesAgentOnExistingBranch;
use App\Models\Repository;
use App\Services\IncusSandboxManager;
use Tests\Support\FakeSandboxManager;

function branchPreparer(): object
{
    return new class
    {
        use ResumesAgentOnExistingBranch;

        public function prepare(IncusSandboxManager $sandbox, Repository $repository, string $branch): void
        {
            $this->prepareExistingBranch($sandbox, 'container', $repository, $branch);
        }
    };
}

it('creates the branch from the default branch when it is not on the remote', function () {
    $sandbox = (new FakeSandboxManager)->failCommand('git ls-remote', exitCode: 2);
    $repository = Repository::factory()->create(['default_branch' => 'main']);

    branchPreparer()->prepare($sandbox, $repository, 'yak/eng-1603');

    expect($sandbox->commandsMatching('git checkout -b yak/eng-1603 origin/main'))->toHaveCount(1);
});

it('checks out the existing remote branch', function () {
    $sandbox = new FakeSandboxManager;
    $repository = Repository::factory()->create(['default_branch' => 'main']);

    branchPreparer()->prepare($sandbox, $repository, 'yak/eng-1603');

    expect($sandbox->commandsMatching('git checkout yak/eng-1603'))->toHaveCount(1)
        ->and($sandbox->commandsMatching('git checkout -b'))->toBe([]);
});

it('fails without creating a branch when the remote lookup errors', function () {
    $sandbox = (new FakeSandboxManager)->failCommand('git ls-remote', 'network down', 128);
    $repository = Repository::factory()->create(['default_branch' => 'main']);

    expect(fn () => branchPreparer()->prepare($sandbox, $repository, 'yak/eng-1603'))->toThrow(RuntimeException::class, 'yak/eng-1603')
        ->and($sandbox->commandsMatching('git checkout -b'))->toBe([]);
});
