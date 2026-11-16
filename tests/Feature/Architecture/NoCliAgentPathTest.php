<?php

use App\Services\IncusSandboxManager;

it('has no CLI agent runner class', function () {
    expect(file_exists(app_path('Agents/SandboxedAgentRunner.php')))->toBeFalse()
        ->and(file_exists(app_path('Jobs/Concerns/RetriesWithoutStaleSession.php')))->toBeFalse();
});

it('has no source file that names the CLI agent path', function () {
    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
        ->filter(fn (SplFileInfo $file): bool => str_contains($file->getContents(), 'claude -p') || str_contains($file->getContents(), 'SandboxedAgentRunner'))
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('no longer moves session transcripts or mounts the host claude directory', function () {
    foreach (['pushSessionTranscript', 'pullSessionTranscript', 'configureClaudeMount', 'sessionTranscriptDir'] as $method) {
        expect(method_exists(IncusSandboxManager::class, $method))->toBeFalse();
    }
});
