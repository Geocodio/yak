<?php

use App\Models\ThreadRepository;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Preview\ThreadPreviewManifestReader;

require_once __DIR__ . '/../../../Support/ThreadPreviewHelpers.php';

it('parses port, cold_start and health probe', function () {
    $thread = previewSandboxThread();
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'sandbox_path' => '/workspace/widgets']);
    queueSandboxOutput("port: 5173\ncold_start: npm run dev\nhealth_probe_path: /up\n");

    $result = app(ThreadPreviewManifestReader::class)->read($thread);

    expect($result->status)->toBe('found')
        ->and($result->manifest->port)->toBe(5173)
        ->and($result->manifest->coldStart)->toBe('npm run dev')
        ->and($result->manifest->healthProbePath)->toBe('/up')
        ->and(app(SandboxManager::class)->calls[0]['arguments']['command'])->toBe(['cat', '/workspace/widgets/.yak/preview.yml']);
});

it('reports a missing file as missing, not an error', function () {
    $thread = previewSandboxThread();
    queueSandboxOutput('', 1);

    $result = app(ThreadPreviewManifestReader::class)->read($thread);

    expect($result->status)->toBe('missing')->and($result->manifest)->toBeNull()->and($result->error)->toBeNull();
});

it('reports invalid yaml with a message', function () {
    $thread = previewSandboxThread();
    queueSandboxOutput("port: [unclosed\n");

    $result = app(ThreadPreviewManifestReader::class)->read($thread);

    expect($result->status)->toBe('invalid')->and($result->error)->toContain('preview.yml');
});

it('reads only the primary repository in a multi-repo thread', function () {
    $thread = previewSandboxThread();
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/extra', 'role' => 'context', 'sandbox_path' => '/workspace/extra']);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'role' => 'primary', 'sandbox_path' => '/workspace/main']);
    queueSandboxOutput("port: 3000\n");

    app(ThreadPreviewManifestReader::class)->read($thread);

    expect(app(SandboxManager::class)->calls)->toHaveCount(1)
        ->and(app(SandboxManager::class)->calls[0]['arguments']['command'])->toBe(['cat', '/workspace/main/.yak/preview.yml']);
});
