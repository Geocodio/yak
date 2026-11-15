<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Enums\DiffFileStatus;
use App\Enums\FileScope;
use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\ListThreadFilesRequest;
use App\Http\Resources\ThreadDiffData;
use App\Models\Thread;
use App\Services\Threads\Files\SandboxFileAvailability;
use App\Services\Threads\Files\SandboxFiles;
use App\Services\Threads\Files\SandboxPath;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Http\JsonResponse;

class ThreadFileController extends Controller
{
    public function __construct(
        private readonly SandboxFiles $files,
        private readonly ThreadSandbox $sandbox,
        private readonly SandboxFileAvailability $availability,
    ) {}

    /**
     * One folder of the repository or the sandbox. A sandbox that cannot serve files answers 409 `sandbox_unavailable`
     * with the state the app shows.
     */
    public function tree(ListThreadFilesRequest $request, Thread $thread): JsonResponse
    {
        $this->ensureAvailable($request, $thread);

        $scope = $request->fileScope();
        $directory = $request->folderPath();
        $listing = $this->files->listFolder($thread, $scope, $directory);
        $this->sandbox->markSessionOpen($thread);

        $paths = $scope === FileScope::Repository ? $this->changedPaths($thread) : ['changed' => [], 'added' => []];

        return response()->json([
            'state' => 'ready',
            'root' => SandboxPath::resolveDirectory($thread, $scope, $directory),
            'entries' => array_map(fn ($entry) => $entry->toArray(), $listing->entries),
            'changedPaths' => $paths['changed'],
            'addedPaths' => $paths['added'],
            'truncated' => $listing->truncated,
        ]);
    }

    private function ensureAvailable(ListThreadFilesRequest $request, Thread $thread): void
    {
        $unavailable = $this->availability->check($thread, $request->user() ?? abort(401));

        if ($unavailable !== null) {
            throw ThreadActionRefused::because(ThreadActionRefused::SANDBOX_UNAVAILABLE, $unavailable->message(), $unavailable->details());
        }
    }

    /**
     * @return array{changed: list<string>, added: list<string>}
     */
    private function changedPaths(Thread $thread): array
    {
        $changed = [];
        $added = [];

        foreach (ThreadDiffData::build($thread)['diff']['files'] ?? [] as $file) {
            if ($file['repo'] === $thread->repo) {
                $changed[] = $file['path'];

                if ($file['status'] === DiffFileStatus::Added->value) {
                    $added[] = $file['path'];
                }
            }
        }

        return ['changed' => $changed, 'added' => $added];
    }
}
