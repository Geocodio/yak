<?php

namespace App\Services\Threads;

use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;

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
    public function sectionsForRestore(Thread $thread, array $refreshRan, bool $setupRan, ?string $wipSha): array
    {
        $workspace = (string) config('yak.sandbox.workspace_path', '/workspace');
        $sections = [];

        if (filled($thread->local_repo_path)) {
            $sections['Paths'] = "The project lives at {$workspace}. {$thread->local_repo_path} is a symlink to it, so paths from the person's machine resolve here.";
        }

        if ($refreshRan !== []) {
            $sections['Refresh commands that ran'] = $refreshRan;
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
