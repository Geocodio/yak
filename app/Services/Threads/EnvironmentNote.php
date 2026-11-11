<?php

namespace App\Services\Threads;

use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Datasets\DatasetAvailability;
use App\Services\Datasets\DatasetAvailabilityState;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Preview\ThreadPreviewHosts;

/**
 * Writes the note the runner reads to learn what a restored sandbox lost.
 * It only ever carries the sections it is given, never environment values.
 */
class EnvironmentNote
{
    private const PATH = '/home/yak/.yak/environment-note.md';

    public function __construct(private SandboxManager $sandboxes) {}

    /**
     * @param  array<string, string|list<string>>  $sections  Heading to body text or bullet items.
     */
    public function write(Thread $thread, array $sections): void
    {
        if ($sections === []) {
            $this->sandboxes->exec($thread, ['rm', '-f', self::PATH]);

            return;
        }

        $this->sandboxes->exec(
            $thread,
            ['sh', '-c', 'mkdir -p ' . dirname(self::PATH) . ' && cat > ' . self::PATH],
            stdin: $this->render($sections),
        );
    }

    /**
     * @param  list<string>  $refreshRan  Refresh commands that ran during the restore.
     * @return array<string, string|list<string>>
     */
    public function sectionsForRestore(Thread $thread, array $refreshRan, bool $setupRan, ?string $wipSha, ?DatasetAvailability $dataset = null): array
    {
        $workspace = (string) config('yak.sandbox.workspace_path', '/workspace');
        $sections = [];

        if (filled($thread->local_repo_path)) {
            $sections['Paths'] = "The project lives at {$workspace}. {$thread->local_repo_path} is a symlink to it, so paths from the person's machine resolve here.";
        }

        if ($refreshRan !== []) {
            $sections['Refresh commands that ran'] = $refreshRan;
        }

        if ($dataset?->message() !== null) {
            $sections['Dataset'] = $dataset->message();
        }

        $preview = app(ThreadPreviewHosts::class)->environmentSection($thread);
        if ($preview !== null) {
            $sections['Preview'] = $preview;
        }

        $restored = [];
        if ($setupRan) {
            $restored[] = 'Ran .yak/setup.sh';
        }
        if ($wipSha !== null) {
            $restored[] = 'Restored uncommitted work from the last WIP commit';
        }
        $restored[] = 'Sandbox-only state (database rows, installs not in .yak/setup.sh) was lost';
        $sections['Restore'] = $restored;

        return $sections;
    }

    /**
     * Repository lists for a multi-repo thread; empty when the thread has one repository.
     *
     * @return array<string, string|list<string>>
     */
    public function sectionsForRepositories(Thread $thread): array
    {
        $repositories = $thread->repositories()->orderByRaw("role = 'primary' desc")->orderBy('id')->get();

        if ($repositories->count() < 2) {
            return [];
        }

        $sections = ['Repositories' => array_values($repositories->map(fn (ThreadRepository $repository): string => "{$repository->sandbox_path}: {$repository->repo} ({$repository->role->value})")->all())];

        if ($repositories->contains(fn (ThreadRepository $repository): bool => $repository->role === RepositoryRole::Context)) {
            $sections['Context edits'] = 'Edits in context repositories are lost when the sandbox is rebuilt.';
        }

        $withoutDataset = [];
        foreach ($repositories->filter(fn (ThreadRepository $repository): bool => $repository->isExtra()) as $repository) {
            $configured = Repository::where('slug', $repository->repo)->first();

            if ($configured !== null && DatasetAvailability::forRepository($configured)->state !== DatasetAvailabilityState::NotDeclared) {
                $withoutDataset[] = "{$repository->repo}: dataset not attached";
            }
        }

        if ($withoutDataset !== []) {
            $sections['Datasets'] = $withoutDataset;
        }

        $failed = $repositories->filter(fn (ThreadRepository $repository): bool => $repository->setup_status === RepositorySetupStatus::Failed);

        if ($failed->isNotEmpty()) {
            $sections['Failed setups'] = array_values($failed->map(fn (ThreadRepository $repository): string => "{$repository->repo}: " . ($repository->setup_error ?? 'setup failed'))->all());
        }

        return $sections;
    }

    /**
     * @param  array<string, string|list<string>>  $sections
     */
    private function render(array $sections): string
    {
        $blocks = [];

        foreach ($sections as $heading => $body) {
            $text = is_array($body) ? implode("\n", array_map(fn (string $item): string => "- {$item}", $body)) : $body;
            $blocks[] = "## {$heading}\n{$text}";
        }

        return implode("\n\n", $blocks) . "\n";
    }
}
