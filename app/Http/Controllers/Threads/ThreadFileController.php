<?php

namespace App\Http\Controllers\Threads;

use App\Enums\FileScope;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Http\Controllers\Controller;
use App\Http\Resources\ThreadDiffData;
use App\Models\Thread;
use App\Services\Threads\Files\SandboxFiles;
use App\Services\Threads\Files\SandboxPath;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ThreadFileController extends Controller
{
    public function __construct(private readonly SandboxFiles $files, private readonly ThreadSandbox $sandbox) {}

    public function tree(Request $request, Thread $thread): JsonResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);

        $scope = $this->scope($request);

        if ($scope === FileScope::Sandbox) {
            abort_unless(Gate::allows('drive', $thread), 403);
        }

        if ($unavailable = $this->unavailable($thread)) {
            return $unavailable;
        }

        $directory = (string) $request->query('path', '');
        $entries = $this->files->tree($thread, $scope, $directory);
        $this->sandbox->touch($thread);

        $changedPaths = [];

        foreach (ThreadDiffData::build($thread)['diff']['files'] ?? [] as $file) {
            $changedPaths[] = $file['path'];
        }

        return response()->json([
            'state' => 'ready',
            'entries' => array_map(fn ($entry) => $entry->toArray(), $entries),
            'root' => SandboxPath::resolveDirectory($thread, $scope, $directory),
            'changedPaths' => $scope === FileScope::Repository ? $changedPaths : [],
        ]);
    }

    public function show(Request $request, Thread $thread): JsonResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);

        $scope = $this->scope($request);

        if ($scope === FileScope::Sandbox) {
            abort_unless(Gate::allows('drive', $thread), 403);
        }

        if ($unavailable = $this->unavailable($thread)) {
            return $unavailable;
        }

        $path = (string) $request->query('path', '');
        $file = $this->files->read($thread, $scope, $path);
        $this->sandbox->touch($thread);

        $canEdit = Gate::allows('drive', $thread)
            && ! $file->isTooLarge
            && ! $file->isBinary
            && SandboxPath::isWritable(SandboxPath::resolve($thread, $scope, $path));

        return response()->json([...$file->toArray(), 'state' => 'ready', 'canEdit' => $canEdit]);
    }

    private function scope(Request $request): FileScope
    {
        $validated = $request->validate(['scope' => ['required', Rule::enum(FileScope::class)]]);

        return FileScope::from($validated['scope']);
    }

    /** A 409 `{state}` answer when the sandbox cannot serve files right now; null when it can. */
    private function unavailable(Thread $thread): ?JsonResponse
    {
        if ($thread->owner_side === ThreadOwnerSide::Device) {
            return response()->json(['state' => 'on_device'], 409);
        }

        $canDrive = Gate::allows('drive', $thread);

        switch ($thread->sandbox_state) {
            case ThreadSandboxState::Running:
                return null;
            case ThreadSandboxState::None:
            case ThreadSandboxState::Destroying:
            case ThreadSandboxState::Destroyed:
                return $thread->isOpen()
                    ? response()->json(['state' => 'destroyed', 'canRestore' => $canDrive], 409)
                    : response()->json(['state' => 'closed_destroyed'], 409);
            case ThreadSandboxState::Starting:
                return response()->json(['state' => 'waking', 'waitingForSlot' => false, 'restoring' => true], 409);
            default:
                if (! $canDrive) {
                    return response()->json(['state' => 'waking', 'waitingForSlot' => false, 'restoring' => false], 409);
                }

                $wake = $this->sandbox->ensureAwake($thread);

                return $wake->ready
                    ? null
                    : response()->json(['state' => 'waking', 'waitingForSlot' => $wake->waitingForSlot, 'restoring' => $wake->restoring], 409);
        }
    }
}
