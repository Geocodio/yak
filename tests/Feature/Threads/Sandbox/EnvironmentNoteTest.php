<?php

use App\Enums\ThreadSandboxState;
use App\Models\Dataset;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\EnvironmentNote;

function noteThread(array $attributes = []): Thread
{
    return Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running, ...$attributes]);
}

function execCalls(): array
{
    return array_column(array_filter(app(SandboxManager::class)->calls, fn (array $call): bool => $call['operation'] === 'exec'), 'arguments');
}

test('writes markdown through stdin to the note path', function () {
    app(EnvironmentNote::class)->write(noteThread(), ['Paths' => 'Mapped.', 'Ran' => ['composer install', 'npm ci']]);

    $call = execCalls()[0];

    expect($call['command'])->toBe(['sh', '-c', 'mkdir -p /home/yak/.yak && cat > /home/yak/.yak/environment-note.md'])
        ->and($call['stdin'])->toBe("## Paths\nMapped.\n\n## Ran\n- composer install\n- npm ci\n");
});

test('empty sections remove the note', function () {
    app(EnvironmentNote::class)->write(noteThread(), []);

    expect(execCalls()[0]['command'])->toBe(['rm', '-f', '/home/yak/.yak/environment-note.md']);
});

test('restore sections name lost state and wip', function () {
    $thread = noteThread(['local_repo_path' => '/Users/ada/app']);

    $sections = app(EnvironmentNote::class)->sectionsForRestore($thread, ['composer install'], true, str_repeat('a', 40));

    expect($sections['Paths'])->toContain('/Users/ada/app')->toContain('/workspace')
        ->and($sections['Refresh commands that ran'])->toBe(['composer install'])
        ->and($sections['Restore'])->toContain('Restored uncommitted work from the last WIP commit')
        ->toContain('Ran .yak/setup.sh')
        ->toContain('Sandbox-only state (database rows, installs not in .yak/setup.sh) was lost');
});

test('sections omit the path mapping without a local path', function () {
    $sections = app(EnvironmentNote::class)->sectionsForRestore(noteThread(), [], false, null);

    expect($sections)->not->toHaveKey('Paths')->not->toHaveKey('Refresh commands that ran')
        ->and($sections['Restore'])->toHaveCount(1);
});

test('redacts nothing it was not given', function () {
    app(EnvironmentNote::class)->write(noteThread(), ['Restore' => ['x']]);

    expect(execCalls()[0]['env'])->toBe([]);
});

test('repository sections list extras, lost context edits, missing datasets and failed setups', function () {
    $thread = noteThread(['repo' => 'acme/app']);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/app']);
    ThreadRepository::factory()->changes()->create(['thread_id' => $thread->id, 'repo' => 'acme/lib', 'sandbox_path' => '/extras/lib']);
    ThreadRepository::factory()->context()->failed('setup.sh exited with 2')->create(['thread_id' => $thread->id, 'repo' => 'acme/docs', 'sandbox_path' => '/extras/docs']);
    $library = Repository::factory()->create(['slug' => 'acme/lib']);
    Dataset::factory()->create(['repository_id' => $library->id]);

    $sections = app(EnvironmentNote::class)->sectionsForRepositories($thread);

    expect($sections['Repositories'])->toContain('/workspace: acme/app (primary)')->toContain('/extras/lib: acme/lib (changes)')->toContain('/extras/docs: acme/docs (context)')
        ->and($sections['Context edits'])->toContain('lost')
        ->and($sections['Datasets'])->toContain('acme/lib: dataset not attached')
        ->and($sections['Failed setups'])->toBe(['acme/docs: setup.sh exited with 2']);
});

test('a single repo thread has no repository sections', function () {
    $thread = noteThread(['repo' => 'acme/app']);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/app']);

    expect(app(EnvironmentNote::class)->sectionsForRepositories($thread))->toBe([]);
});
