<?php

use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\SandboxArtifactCollector;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('artifacts');
});

it('wipes a stale .yak-artifacts dir before pulling so retries do not hit mkdir: file exists', function () {
    $task = YakTask::factory()->create();

    // Simulate a prior attempt that pulled artifacts but whose ProcessCIResultJob
    // never ran (push failed, etc). The files sit on disk with no DB rows.
    $stalePath = Storage::disk('artifacts')->path("{$task->id}/.yak-artifacts");
    mkdir($stalePath, 0755, true);
    file_put_contents($stalePath . '/old.png', 'stale-screenshot');

    // Stub the sandbox so fileExists says yes and pullDirectory writes fresh files
    // to the same destination `incus file pull -r` would populate.
    $sandbox = new class(task: $task) extends IncusSandboxManager
    {
        public bool $pullCalled = false;

        public function __construct(private YakTask $task) {}

        public function fileExists(string $containerName, string $path): bool
        {
            return true;
        }

        public function pullDirectory(string $containerName, string $remotePath, string $localPath): void
        {
            $this->pullCalled = true;

            $target = $localPath . '/.yak-artifacts';
            if (is_dir($target)) {
                throw new RuntimeException("mkdir {$target}: file exists");
            }
            mkdir($target, 0755, true);
            file_put_contents($target . '/fresh.png', 'new-screenshot');
        }
    };

    SandboxArtifactCollector::collect($sandbox, 'task-' . $task->id, $task);

    expect($sandbox->pullCalled)->toBeTrue();

    $freshPath = Storage::disk('artifacts')->path("{$task->id}/.yak-artifacts");
    expect(file_exists($freshPath . '/old.png'))->toBeFalse()
        ->and(file_exists($freshPath . '/fresh.png'))->toBeTrue();
});
