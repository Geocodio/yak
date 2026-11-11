<?php

namespace App\Services\Threads\Repos;

use App\DataTransferObjects\YakToolCall;
use App\DataTransferObjects\YakToolResult;
use App\Enums\RepositoryRole;
use App\Models\Thread;
use App\Services\Threads\YakToolRouter;

/**
 * Serves `add_repository` and passes every other tool to the router it wraps.
 */
class MultiRepoToolRouter implements YakToolRouter
{
    public function __construct(private readonly YakToolRouter $inner, private readonly AddRepository $add) {}

    public function handle(Thread $thread, YakToolCall $call): YakToolResult
    {
        if ($call->tool !== 'add_repository') {
            return $this->inner->handle($thread, $call);
        }

        $role = RepositoryRole::tryFrom((string) ($call->input['role'] ?? 'changes'));
        $slug = (string) ($call->input['repo'] ?? '');

        if ($role === null) {
            return new YakToolResult(true, 'The role must be changes or context.');
        }

        $outcome = $this->add->add($thread, $slug, $role, trim((string) ($call->input['reason'] ?? '')));

        return match ($outcome->status) {
            'added' => new YakToolResult(false, "Added {$slug} at {$outcome->path} ({$role->value}). Use absolute paths between repositories."),
            'duplicate' => new YakToolResult(false, "{$slug} is already in this thread at {$outcome->path}."),
            'setup_failed' => new YakToolResult(true, "Cloned {$slug} at {$outcome->path} but its setup failed: {$outcome->message}. Stop and ask the person how to proceed; do not continue in that repo."),
            'no_access' => new YakToolResult(true, "Yak cannot access {$slug}. {$outcome->message}"),
            default => new YakToolResult(true, (string) $outcome->message),
        };
    }
}
