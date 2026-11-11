<?php

use App\Enums\ThreadOwnerSide;
use App\Models\ThreadRepository;
use App\Services\Threads\EnvironmentNote;
use App\Services\Threads\Preview\ThreadPreviewHosts;

require_once __DIR__ . '/../../../Support/ThreadPreviewHelpers.php';

beforeEach(function () {
    config(['yak.deployments.hostname_suffix' => 'yak.example.com']);
});

it('names the preview url and says the agent may restart the dev server', function () {
    $thread = previewSandboxThread();
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'sandbox_path' => '/workspace/widgets']);
    queueSandboxOutput("port: 5173\ncold_start: npm run dev\nhealth_probe_path: /up\n");

    $section = app(ThreadPreviewHosts::class)->environmentSection($thread);

    expect($section)->toContain(app(ThreadPreviewHosts::class)->urlFor($thread))
        ->toContain('You may restart it')
        ->toContain('/up')
        ->not->toContain('—');
});

it('is included in the restore sections', function () {
    $thread = previewSandboxThread();
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'sandbox_path' => '/workspace/widgets']);
    queueSandboxOutput("port: 3000\ncold_start: bin/serve\nhealth_probe_path: /up\n");

    $sections = app(EnvironmentNote::class)->sectionsForRestore($thread, [], false, null);

    expect($sections)->toHaveKey('Preview');
});

it('has no preview section for a thread owned by a local machine', function () {
    $thread = previewSandboxThread();
    $thread->forceFill(['owner_side' => ThreadOwnerSide::Device])->save();

    expect(app(ThreadPreviewHosts::class)->environmentSection($thread))->toBeNull();
});

it('has no preview section when the manifest is missing', function () {
    $thread = previewSandboxThread();
    queueSandboxOutput('', 1);

    expect(app(ThreadPreviewHosts::class)->environmentSection($thread))->toBeNull();
});
