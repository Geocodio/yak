<?php

namespace App\Services\Stacks;

use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\SandboxRestorer;
use App\Services\Threads\ThreadSandbox;
use App\Support\SecretFilePatterns;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Gives an implement, mockup or general child its own sandbox: a copy of the
 * parent's sandbox, or a fresh clone plus the parent's uncommitted changes
 * when the copy fails; a child in another repo is restored from that repo's
 * own template instead. A child that cannot start because the parent is still
 * waking up is marked `queued_for_slot_at` and left for the caller to retry.
 */
class ChildSandboxProvisioner
{
    public function __construct(
        private readonly SandboxManager $sandboxes,
        private readonly ThreadSandbox $lifecycle,
        private readonly SandboxRestorer $restorer,
    ) {}

    public function provision(Thread $child): void
    {
        if ($child->kind === ThreadKind::Research) {
            throw new LogicException('A research child runs in its parent sandbox; use ResearchChildRunner.');
        }

        $parent = Thread::query()->withoutGlobalScopes()->findOrFail($child->parent_thread_id);

        if ($child->repo !== $parent->repo) {
            $this->provisionFromOwnTemplate($child);

            return;
        }

        if (! in_array($parent->sandbox_state, [ThreadSandboxState::Running, ThreadSandboxState::Paused], true)) {
            $wake = $this->lifecycle->ensureAwake($parent);
            $parent->refresh();

            if (! $wake->ready) {
                $child->forceFill(['queued_for_slot_at' => $child->queued_for_slot_at ?? now()])->save();

                return;
            }
        }

        try {
            $this->sandboxes->copyFrom($parent, $child);
        } catch (Throwable) {
            $this->cloneWithParentChanges($parent, $child);
            $child->forceFill(['queued_for_slot_at' => null])->save();

            return;
        }

        $this->sandboxes->start($child);
        $this->recordRunning($child);
        $this->checkoutBranch($child);

        $child->forceFill(['queued_for_slot_at' => null])->save();
    }

    /**
     * A child in another repo shares nothing with the parent sandbox: it is
     * restored from its own repo's template and branches off that default branch.
     */
    private function provisionFromOwnTemplate(Thread $child): void
    {
        if (! $this->restorer->restore($child)) {
            throw new RuntimeException("Could not build the sandbox of sub-thread {$child->name}.");
        }

        $this->recordContainer($child);
        $this->checkoutBranch($child);
        $child->forceFill(['queued_for_slot_at' => null])->save();
    }

    private function cloneWithParentChanges(Thread $parent, Thread $child): void
    {
        $head = trim($this->mustRun($parent, ['git', 'rev-parse', 'HEAD']));
        $secrets = SecretFilePatterns::filter($this->lines($this->mustRun($parent, ['git', 'diff', '--name-only', 'HEAD'])));
        $diff = $this->mustRun($parent, ['git', 'diff', '--binary', 'HEAD', '--', '.', ...array_map(fn (string $path): string => ":(exclude){$path}", $secrets)]);
        $untrackedAll = $this->lines($this->mustRun($parent, ['git', 'ls-files', '--others', '--exclude-standard']));
        $untracked = array_values(array_diff($untrackedAll, SecretFilePatterns::filter($untrackedAll)));
        $archive = $untracked === [] ? '' : trim($this->mustRun($parent, ['bash', '-c', 'tar -cf - -T - | base64 -w0'], implode("\n", $untracked)));

        if (! $this->restorer->restore($child)) {
            throw new RuntimeException("Could not build the sandbox of sub-thread {$child->name}.");
        }

        $this->recordContainer($child);
        $this->mustRun($child, ['git', 'checkout', '-B', (string) ($child->branch ?? $parent->branch ?? 'HEAD'), $head]);

        if (trim($diff) !== '') {
            $this->mustRun($child, ['git', 'apply', '--binary', '--whitespace=nowarn'], $diff);
        }

        if ($archive !== '') {
            $this->mustRun($child, ['bash', '-c', 'base64 -d | tar -xf -'], $archive);
        }
    }

    private function checkoutBranch(Thread $child): void
    {
        if (blank($child->branch)) {
            return;
        }

        $this->mustRun($child, ['git', 'checkout', '-B', (string) $child->branch]);
    }

    private function recordRunning(Thread $child): void
    {
        $this->recordContainer($child);
        $child->forceFill(['sandbox_state' => ThreadSandboxState::Running, 'sandbox_state_changed_at' => now()])->save();
    }

    /** The real manager records the container itself; a manager that does not leaves the conventional name. */
    private function recordContainer(Thread $child): void
    {
        $child->refresh();

        if ($child->sandbox_container === null) {
            $child->forceFill(['sandbox_container' => "yak-thread-{$child->id}"])->save();
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRun(Thread $thread, array $command, ?string $stdin = null): string
    {
        $result = $this->sandboxes->exec($thread, $command, $stdin);

        if ($result->failed()) {
            throw new RuntimeException("{$command[0]} {$command[1]} failed (exit {$result->exitCode()})");
        }

        return $result->output();
    }

    /**
     * @return list<string>
     */
    private function lines(string $output): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $output)), fn (string $line): bool => $line !== ''));
    }
}
