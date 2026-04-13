<?php

use App\GitOperations;
use App\Models\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| Branch Creation
|--------------------------------------------------------------------------
*/

test('createBranch fetches origin and creates branch from origin/default_branch', function () {
    Process::fake([
        'git reset --hard' => Process::result(''),
        'git clean -fd' => Process::result(''),
        'git fetch *' => Process::result(''),
        'git checkout -b *' => Process::result(''),
        'git checkout *' => Process::result(''),
    ]);

    $repository = Repository::factory()->create([
        'slug' => 'test-repo',
        'path' => '/home/yak/repos/test-repo',
        'default_branch' => 'main',
    ]);

    $branchName = GitOperations::createBranch($repository, 'ISSUE-42');

    expect($branchName)->toBe('yak/ISSUE-42');

    Process::assertRan(fn ($process) => $process->command === 'git fetch origin main');
    Process::assertRan(fn ($process) => $process->command === 'git checkout -b yak/ISSUE-42 origin/main');
});

test('createBranch uses repository default_branch for origin ref', function () {
    Process::fake([
        'git reset --hard' => Process::result(''),
        'git clean -fd' => Process::result(''),
        'git fetch *' => Process::result(''),
        'git checkout -b *' => Process::result(''),
        'git checkout *' => Process::result(''),
    ]);

    $repository = Repository::factory()->create([
        'slug' => 'dev-repo',
        'path' => '/home/yak/repos/dev-repo',
        'default_branch' => 'develop',
    ]);

    GitOperations::createBranch($repository, 'FIX-99');

    Process::assertRan(fn ($process) => $process->command === 'git fetch origin develop');
    Process::assertRan(fn ($process) => $process->command === 'git checkout -b yak/FIX-99 origin/develop');
});

test('createBranch sanitizes special characters in external id', function () {
    Process::fake([
        'git reset --hard' => Process::result(''),
        'git clean -fd' => Process::result(''),
        'git fetch *' => Process::result(''),
        'git checkout -b *' => Process::result(''),
        'git checkout *' => Process::result(''),
    ]);

    $repository = Repository::factory()->create([
        'slug' => 'san-repo',
        'path' => '/home/yak/repos/san-repo',
        'default_branch' => 'main',
    ]);

    $branchName = GitOperations::createBranch($repository, 'fix: auth [bug]');

    expect($branchName)->toBe('yak/fix-auth-bug');

    Process::assertRan(fn ($process) => $process->command === 'git checkout -b yak/fix-auth-bug origin/main');
});

/*
|--------------------------------------------------------------------------
| Branch Pushing
|--------------------------------------------------------------------------
*/

test('pushBranch runs git push origin with branch name', function () {
    Process::fake([
        'git push *' => Process::result(''),
    ]);

    $repository = Repository::factory()->create([
        'slug' => 'push-repo',
        'path' => '/home/yak/repos/push-repo',
    ]);

    GitOperations::pushBranch($repository, 'yak/ISSUE-42');

    Process::assertRan(fn ($process) => $process->command === 'git push origin yak/ISSUE-42');
});

/*
|--------------------------------------------------------------------------
| Force Push
|--------------------------------------------------------------------------
*/

test('forcePushBranch runs git push --force origin with branch name', function () {
    Process::fake([
        'git push *' => Process::result(''),
    ]);

    $repository = Repository::factory()->create([
        'slug' => 'fp-repo',
        'path' => '/home/yak/repos/fp-repo',
    ]);

    GitOperations::forcePushBranch($repository, 'yak/ISSUE-42');

    Process::assertRan(fn ($process) => $process->command === 'git push --force origin yak/ISSUE-42');
});

/*
|--------------------------------------------------------------------------
| Post-task Cleanup
|--------------------------------------------------------------------------
*/

test('cleanup checks out default branch and deletes task branch', function () {
    Process::fake([
        'git checkout *' => Process::result(''),
        'git branch *' => Process::result(''),
    ]);

    $repository = Repository::factory()->create([
        'slug' => 'cl-repo',
        'path' => '/home/yak/repos/cl-repo',
        'default_branch' => 'main',
    ]);

    GitOperations::cleanup($repository, 'yak/ISSUE-42');

    Process::assertRan(fn ($process) => $process->command === 'git checkout main');
    Process::assertRan(fn ($process) => $process->command === 'git branch -D yak/ISSUE-42');
});

test('cleanup skips branch deletion when branch name is null', function () {
    Process::fake([
        'git checkout *' => Process::result(''),
    ]);

    $repository = Repository::factory()->create([
        'slug' => 'cn-repo',
        'path' => '/home/yak/repos/cn-repo',
        'default_branch' => 'main',
    ]);

    GitOperations::cleanup($repository, null);

    Process::assertRan(fn ($process) => $process->command === 'git checkout main');
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'git branch -D'));
});

test('cleanup skips branch deletion when branch name is empty string', function () {
    Process::fake([
        'git checkout *' => Process::result(''),
    ]);

    $repository = Repository::factory()->create([
        'slug' => 'ce-repo',
        'path' => '/home/yak/repos/ce-repo',
        'default_branch' => 'main',
    ]);

    GitOperations::cleanup($repository, '');

    Process::assertRan(fn ($process) => $process->command === 'git checkout main');
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'git branch -D'));
});

/*
|--------------------------------------------------------------------------
| Checkout Operations
|--------------------------------------------------------------------------
*/

test('checkoutBranch runs git checkout with given branch name', function () {
    Process::fake([
        'git checkout *' => Process::result(''),
    ]);

    $repository = Repository::factory()->create([
        'slug' => 'co-repo',
        'path' => '/home/yak/repos/co-repo',
    ]);

    GitOperations::checkoutBranch($repository, 'yak/ISSUE-42');

    Process::assertRan(fn ($process) => $process->command === 'git checkout yak/ISSUE-42');
});

test('checkoutDefaultBranch runs git checkout with default branch', function () {
    Process::fake([
        'git checkout *' => Process::result(''),
    ]);

    $repository = Repository::factory()->create([
        'slug' => 'cd-repo',
        'path' => '/home/yak/repos/cd-repo',
        'default_branch' => 'develop',
    ]);

    GitOperations::checkoutDefaultBranch($repository);

    Process::assertRan(fn ($process) => $process->command === 'git checkout develop');
});

/*
|--------------------------------------------------------------------------
| Repo Refresh
|--------------------------------------------------------------------------
*/

test('refreshRepo runs git fetch origin with default branch', function () {
    Process::fake([
        'git fetch *' => Process::result(''),
    ]);

    $repository = Repository::factory()->create([
        'slug' => 'rf-repo',
        'path' => '/home/yak/repos/rf-repo',
        'default_branch' => 'main',
    ]);

    GitOperations::refreshRepo($repository);

    Process::assertRan(fn ($process) => $process->command === 'git fetch origin main');
});

/*
|--------------------------------------------------------------------------
| Refresh Repos Command
|--------------------------------------------------------------------------
*/

test('yak:refresh-repos fetches all active repositories', function () {
    Process::fake([
        'git fetch *' => Process::result(''),
    ]);

    Repository::factory()->create([
        'slug' => 'active-1',
        'path' => '/home/yak/repos/active-1',
        'is_active' => true,
        'default_branch' => 'main',
    ]);

    Repository::factory()->create([
        'slug' => 'active-2',
        'path' => '/home/yak/repos/active-2',
        'is_active' => true,
        'default_branch' => 'develop',
    ]);

    $this->artisan('yak:refresh-repos')->assertExitCode(0);

    Process::assertRan(fn ($process) => $process->command === 'git fetch origin main');
    Process::assertRan(fn ($process) => $process->command === 'git fetch origin develop');
});

test('yak:refresh-repos skips inactive repositories', function () {
    Process::fake([
        'git fetch *' => Process::result(''),
    ]);

    Repository::factory()->create([
        'slug' => 'active-repo',
        'path' => '/home/yak/repos/active-repo',
        'is_active' => true,
        'default_branch' => 'main',
    ]);

    Repository::factory()->inactive()->create([
        'slug' => 'inactive-repo',
        'path' => '/home/yak/repos/inactive-repo',
        'default_branch' => 'main',
    ]);

    $this->artisan('yak:refresh-repos')->assertExitCode(0);

    Process::assertRan(fn ($process) => $process->command === 'git fetch origin main');
    // Only one fetch should have run (for the active repo)
    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'git fetch'), 1);
});

/*
|--------------------------------------------------------------------------
| Schedule Registration
|--------------------------------------------------------------------------
*/

test('yak:refresh-repos is scheduled every 30 minutes', function () {
    $schedule = app(Schedule::class);
    $events = $schedule->events();

    $refreshEvent = collect($events)->first(fn ($event) => str_contains($event->command ?? '', 'yak:refresh-repos'));

    expect($refreshEvent)->not->toBeNull()
        ->and($refreshEvent->expression)->toBe('*/30 * * * *');
});
