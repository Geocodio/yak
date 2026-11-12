<?php

use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\Repos\ThreadRepositoryPrompt;

function promptThread(): Thread
{
    $thread = Thread::factory()->create(['repo' => 'acme/app']);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/app']);

    return $thread;
}

test('a single repo thread gets an empty section and no directories', function () {
    $thread = promptThread();

    expect(ThreadRepositoryPrompt::section($thread))->toBe('')
        ->and(ThreadRepositoryPrompt::extraDirectories($thread))->toBe([])
        ->and(ThreadRepositoryPrompt::setupFailureNotice($thread))->toBeNull();
});

test('extras are listed with absolute paths and roles', function () {
    $thread = promptThread();
    ThreadRepository::factory()->changes()->create(['thread_id' => $thread->id, 'repo' => 'acme/lib', 'sandbox_path' => '/extras/lib']);
    ThreadRepository::factory()->context()->create(['thread_id' => $thread->id, 'repo' => 'acme/docs', 'sandbox_path' => '/extras/docs']);

    $section = ThreadRepositoryPrompt::section($thread);

    expect($section)->toContain('/workspace')->toContain('acme/app')
        ->toContain('/extras/lib')->toContain('acme/lib')
        ->toContain('/extras/docs')->toContain('acme/docs')
        ->toContain('changes')->toContain('context')
        ->toContain('/extras/lib')->toContain('npm install --no-save')
        ->toContain('Never commit')->toContain('never push')
        ->and(ThreadRepositoryPrompt::extraDirectories($thread))->toBe(['/extras/lib', '/extras/docs']);
});

test('the setup failure notice appears only with a failed row', function () {
    $thread = promptThread();
    ThreadRepository::factory()->changes()->create(['thread_id' => $thread->id, 'repo' => 'acme/web']);

    expect(ThreadRepositoryPrompt::setupFailureNotice($thread))->toBeNull();

    ThreadRepository::factory()->changes()->failed()->create(['thread_id' => $thread->id, 'repo' => 'acme/broken']);

    expect(ThreadRepositoryPrompt::setupFailureNotice($thread))
        ->toBe('Setup failed for acme/broken. Stop and ask the person before continuing in that repo.');
});
