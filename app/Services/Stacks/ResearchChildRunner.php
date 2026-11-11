<?php

namespace App\Services\Stacks;

use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\ThreadCommands;
use App\Services\Threads\ThreadSandbox;
use RuntimeException;

/**
 * A research child is a second runner in its parent's container: it works in
 * a read-only git worktree at the parent's HEAD, has its own config directory
 * and placeholder, and takes no awake slot of its own.
 */
class ResearchChildRunner
{
    private const WORKTREE_ROOT = '/workspace-research';

    public function __construct(
        private readonly SandboxManager $sandboxes,
        private readonly ThreadSandbox $lifecycle,
        private readonly ThreadCommands $commands,
        private readonly SessionFileSync $sessions,
    ) {}

    public static function worktreePath(Thread $child): string
    {
        return self::WORKTREE_ROOT . '/' . $child->name;
    }

    /**
     * Attaches the child to the parent's container and builds its worktree.
     * A parent that cannot wake up yet leaves the child queued.
     */
    public function prepare(Thread $child): void
    {
        $parent = $this->parentOf($child);

        if (! $this->wake($parent)) {
            $child->forceFill(['queued_for_slot_at' => $child->queued_for_slot_at ?? now()])->save();

            return;
        }

        $child->forceFill([
            'sandbox_container' => $parent->sandbox_container,
            'sandbox_host' => $parent->sandbox_host,
            'sandbox_state' => $parent->sandbox_state,
            'sandbox_state_changed_at' => now(),
            'claude_config_dir' => "/home/yak/.claude-{$child->id}",
            'queued_for_slot_at' => null,
        ])->save();

        $this->mustRun($parent, ['mkdir', '-p', $child->claude_config_dir]);
        $this->addWorktree($parent, $child);
    }

    public function start(Thread $child, ClaudeAccount $account, string $appendSystemPrompt): void
    {
        $this->commands->startRunner(
            $child,
            $account,
            $child->activeSession()?->session_id,
            $appendSystemPrompt,
            self::worktreePath($child),
        );
    }

    /**
     * Recreates the worktree at the parent's current HEAD for a later message
     * to a finished research child, restoring the parent's sandbox first.
     */
    public function refreshWorktree(Thread $child): void
    {
        $parent = $this->parentOf($child);

        if (! $this->wake($parent)) {
            $child->forceFill(['queued_for_slot_at' => $child->queued_for_slot_at ?? now()])->save();

            return;
        }

        $path = self::worktreePath($child);
        $this->sandboxes->exec($parent, ['bash', '-c', 'chmod -R u+w "$1" 2>/dev/null; git worktree remove --force "$1"', 'bash', $path]);
        $this->mustRun($parent, ['git', 'worktree', 'prune']);
        $this->addWorktree($parent, $child);
        $this->mustRun($parent, ['mkdir', '-p', $child->claude_config_dir]);

        $child->forceFill([
            'sandbox_container' => $parent->sandbox_container,
            'sandbox_state' => $parent->sandbox_state,
            'sandbox_state_changed_at' => now(),
            'queued_for_slot_at' => null,
        ])->save();

        $this->sessions->restoreIntoSandbox($child);
    }

    private function addWorktree(Thread $parent, Thread $child): void
    {
        $path = self::worktreePath($child);

        $this->mustRun($parent, ['bash', '-c', 'mkdir -p "$(dirname "$1")" && git worktree add --detach "$1" HEAD && chmod -R a-w "$1"', 'bash', $path]);
    }

    private function wake(Thread $parent): bool
    {
        $wake = $this->lifecycle->ensureAwake($parent);
        $parent->refresh();

        return $wake->ready;
    }

    private function parentOf(Thread $child): Thread
    {
        return Thread::query()->withoutGlobalScopes()->findOrFail($child->parent_thread_id);
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRun(Thread $thread, array $command): void
    {
        $result = $this->sandboxes->exec($thread, $command);

        if ($result->failed()) {
            throw new RuntimeException("{$command[0]} failed in the sandbox (exit {$result->exitCode()})");
        }
    }
}
