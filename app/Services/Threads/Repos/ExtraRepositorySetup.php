<?php

namespace App\Services\Threads\Repos;

use App\DataTransferObjects\RepositorySetupResult;
use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use App\Events\ThreadRepositoryChanged;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\RefreshTriggers;
use Illuminate\Support\Collection;

/**
 * Clones a thread's extra repositories into the sandbox in parallel, then runs
 * each one's `.yak/setup.sh` and refresh triggers. A repository that fails
 * keeps its row as `failed`; the others are unaffected. Extras never get a
 * dataset, and a repository's build credentials only exist in the environment
 * of its own setup subshell.
 */
class ExtraRepositorySetup
{
    private const OUTPUT_LIMIT = 65536;

    private const MIRROR_DIRECTORY = '/var/cache/yak/mirrors';

    public function __construct(private SandboxManager $sandboxes, private RefreshTriggers $refresh, private ThreadRepositoryBranches $branches) {}

    public function setUp(Thread $thread, ThreadRepository $repository): RepositorySetupResult
    {
        return $this->setUpAll($thread, collect([$repository]))[0];
    }

    /**
     * @param  Collection<int, ThreadRepository>  $repositories
     * @return list<RepositorySetupResult>
     */
    public function setUpAll(Thread $thread, Collection $repositories): array
    {
        $repositories = array_values($repositories->all());

        if ($repositories === []) {
            return [];
        }

        $errors = [];
        $outputs = [];
        $seconds = [];

        foreach ($repositories as $repository) {
            $this->transition($thread, $repository, RepositorySetupStatus::Cloning, ['setup_error' => null, 'setup_output' => null, 'setup_started_at' => now()]);
        }

        $cloneBodies = array_map(fn (ThreadRepository $repository): string => $this->cloneBody($thread, $repository), $repositories);
        $cloned = $this->runParallel($thread, $cloneBodies);

        $cloneOk = [];
        foreach ($repositories as $index => $repository) {
            $run = $cloned[$index] ?? ['exit' => 1, 'output' => 'The clone produced no result.', 'seconds' => 0];
            $outputs[$index] = $run['output'];
            $seconds[$index] = $run['seconds'];

            if ($run['exit'] === 0) {
                $cloneOk[] = $index;
            } else {
                $errors[$index] = $this->describeFailure($run['output'], $run['exit']);
            }
        }

        foreach ($cloneOk as $index) {
            $repository = $repositories[$index];
            $this->transition($thread, $repository, RepositorySetupStatus::Setup, $this->branchAttributes($thread, $repository));
        }

        $work = $cloneOk === [] ? [] : $this->planWork($thread, $repositories, $cloneOk);
        $setupBodies = [];
        $setupIndexes = [];

        foreach ($work as $index => $plan) {
            if ($plan['hasSetup'] || $plan['commands'] !== []) {
                $setupBodies[] = $this->setupBody($repositories[$index], $plan);
                $setupIndexes[] = $index;
            }
        }

        $setupRuns = $setupBodies === [] ? [] : $this->runParallel($thread, $setupBodies);

        foreach ($setupIndexes as $position => $index) {
            $run = $setupRuns[$position] ?? ['exit' => 1, 'output' => 'The setup produced no result.', 'seconds' => 0];
            $outputs[$index] .= "\n" . $run['output'];

            if ($run['exit'] !== 0) {
                $errors[$index] = $this->describeFailure($run['output'], $run['exit']);
            }
        }

        $results = [];

        foreach ($repositories as $index => $repository) {
            $secrets = $this->secretsFor($repository);
            $output = mb_strcut($this->scrub(trim($outputs[$index] ?? ''), $secrets), 0, self::OUTPUT_LIMIT);
            $error = isset($errors[$index]) ? $this->scrub($errors[$index], $secrets) : null;
            $status = $error === null ? RepositorySetupStatus::Ready : RepositorySetupStatus::Failed;
            $attributes = ['setup_error' => $error, 'setup_output' => $output];

            if ($error === null && isset($work[$index]) && $work[$index]['head'] !== '') {
                $attributes['last_refresh_commit'] = $work[$index]['head'];
            }

            $this->transition($thread, $repository, $status, $attributes);
            $results[] = new RepositorySetupResult($repository->repo, $status, $error, $output, (float) ($seconds[$index] ?? 0));
        }

        return $results;
    }

    /**
     * @return array<string, string>
     */
    private function branchAttributes(Thread $thread, ThreadRepository $repository): array
    {
        $branch = $repository->role === RepositoryRole::Changes && $repository->branch === null ? $this->branches->branchFor($thread, $repository) : null;

        return $branch === null ? [] : ['branch' => $branch];
    }

    private function cloneBody(Thread $thread, ThreadRepository $repository): string
    {
        $slug = $repository->repo;
        $destination = escapeshellarg($repository->sandbox_path);
        $default = escapeshellarg($this->defaultBranch($slug));
        $mirror = escapeshellarg(self::MIRROR_DIRECTORY . "/{$slug}.git");
        $url = escapeshellarg("https://github.com/{$slug}.git");

        $lines = ["[ -d {$destination}/.git ] || git clone --reference-if-able {$mirror} --dissociate {$url} {$destination} || exit \$?", "cd {$destination} || exit \$?"];

        $changesBranch = $repository->role === RepositoryRole::Changes ? $this->branches->branchFor($thread, $repository) : null;

        if ($changesBranch !== null) {
            $branch = escapeshellarg($changesBranch);
            $lines[] = "if git fetch origin {$branch}; then git checkout -B {$branch} FETCH_HEAD; else git checkout -B {$branch} origin/{$default}; fi";
        } else {
            $lines[] = "git checkout {$default}";
        }

        return implode("\n", $lines);
    }

    /**
     * Reads each cloned repository's setup script, refresh map and changed files.
     *
     * @param  list<ThreadRepository>  $repositories
     * @param  list<int>  $indexes
     * @return array<int, array{hasSetup: bool, commands: list<string>, head: string}>
     */
    private function planWork(Thread $thread, array $repositories, array $indexes): array
    {
        $script = '';

        foreach ($indexes as $index) {
            $repository = $repositories[$index];
            $since = $repository->last_refresh_commit === null ? null : escapeshellarg($repository->last_refresh_commit);
            $changed = $since === null ? 'git ls-files' : "git diff --name-only {$since} HEAD 2>/dev/null || git ls-files";
            $script .= "echo '@@YAK_INSPECT {$index}'\n(\ncd " . escapeshellarg($repository->sandbox_path) . " || exit 0\n"
                . "[ -f .yak/setup.sh ] && echo '@@YAK_SETUP'\n"
                . "echo '@@YAK_HEAD'; git rev-parse HEAD\n"
                . "echo '@@YAK_CONFIG'; cat .yak/config.yml 2>/dev/null\n"
                . "echo '@@YAK_FILES'; {$changed}\n"
                . "echo '@@YAK_END'\n)\n";
        }

        $output = $this->sandboxes->exec($thread, ['bash', '-s'], stdin: $script)->output();
        $plans = [];

        foreach ($indexes as $index) {
            $plans[$index] = ['hasSetup' => false, 'commands' => [], 'head' => ''];
        }

        preg_match_all('/^@@YAK_INSPECT (\d+)\n(.*?)(?=^@@YAK_INSPECT |\z)/ms', $output, $chunks, PREG_SET_ORDER);

        foreach ($chunks as [, $index, $body]) {
            $index = (int) $index;

            if (! isset($plans[$index])) {
                continue;
            }

            $section = fn (string $name, string $next): string => preg_match("/^@@YAK_{$name}\n(.*?)^@@YAK_{$next}\$/ms", $body, $match) === 1 ? $match[1] : '';
            $files = array_values(array_filter(array_map('trim', explode("\n", $section('FILES', 'END')))));
            $map = $this->refresh->parseRefreshMap($section('CONFIG', 'FILES'), $thread->id);

            $plans[$index] = [
                'hasSetup' => preg_match('/^@@YAK_SETUP$/m', $body) === 1,
                'commands' => $this->refresh->matching($map, $files),
                'head' => trim($section('HEAD', 'CONFIG')),
            ];
        }

        return $plans;
    }

    /**
     * @param  array{hasSetup: bool, commands: list<string>, head: string}  $plan
     */
    private function setupBody(ThreadRepository $repository, array $plan): string
    {
        $lines = ['cd ' . escapeshellarg($repository->sandbox_path) . ' || exit $?'];

        foreach ($this->credentialsFor($repository) as $name => $value) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1) {
                $lines[] = "export {$name}=" . escapeshellarg($value);
            }
        }

        if ($plan['hasSetup']) {
            $lines[] = 'bash .yak/setup.sh || exit $?';
        }

        foreach ($plan['commands'] as $command) {
            $lines[] = 'echo ' . escapeshellarg("$ {$command}");
            $lines[] = 'sh -c ' . escapeshellarg($command) . ' || exit $?';
        }

        return implode("\n", $lines);
    }

    /**
     * Runs each body in its own background subshell inside one exec and reads the exit codes back.
     *
     * @param  list<string>  $bodies
     * @return array<int, array{exit: int, output: string, seconds: int}>
     */
    private function runParallel(Thread $thread, array $bodies): array
    {
        $lines = ['d=$(mktemp -d)'];

        foreach ($bodies as $index => $body) {
            $lines[] = "( s=\$SECONDS; ( {$body}\n) > \"\$d/{$index}.log\" 2>&1; code=\$?; echo \$((SECONDS - s)) > \"\$d/{$index}.sec\"; exit \$code ) &";
            $lines[] = "p{$index}=\$!";
        }

        foreach (array_keys($bodies) as $index) {
            $lines[] = "wait \$p{$index}; code=\$?";
            $lines[] = "echo '@@YAK_BEGIN {$index}'; cat \"\$d/{$index}.log\"; echo; echo \"@@YAK_EXIT {$index} \$code \$(cat \"\$d/{$index}.sec\")\"";
        }

        $lines[] = 'rm -rf "$d"';

        $output = $this->sandboxes->exec($thread, ['bash', '-s'], stdin: implode("\n", $lines) . "\n")->output();
        $runs = [];

        preg_match_all('/^@@YAK_BEGIN (\d+)\n(.*?)^@@YAK_EXIT \d+ (\d+) (\d+)/ms', $output, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $index, $log, $exit, $seconds]) {
            $runs[(int) $index] = ['exit' => (int) $exit, 'output' => trim($log), 'seconds' => (int) $seconds];
        }

        return $runs;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(Thread $thread, ThreadRepository $repository, RepositorySetupStatus $status, array $attributes = []): void
    {
        $repository->update([...$attributes, 'setup_status' => $status]);

        ThreadRepositoryChanged::dispatch($thread, $repository);
    }

    private function describeFailure(string $output, int $exit): string
    {
        $first = trim(strtok(trim($output), "\n") ?: '');

        return $first === '' ? "exit {$exit}" : "{$first} (exit {$exit})";
    }

    private function defaultBranch(string $slug): string
    {
        return Repository::where('slug', $slug)->value('default_branch') ?: 'main';
    }

    /**
     * @return array<string, string>
     */
    private function credentialsFor(ThreadRepository $repository): array
    {
        $configured = Repository::where('slug', $repository->repo)->first();

        return $configured === null ? [] : array_map('strval', $configured->build_credentials ?? []);
    }

    /**
     * @return list<string>
     */
    private function secretsFor(ThreadRepository $repository): array
    {
        return array_values(array_filter($this->credentialsFor($repository), fn (string $value): bool => $value !== ''));
    }

    /**
     * Removes build credential values and URL userinfo before text is stored.
     *
     * @param  list<string>  $secrets
     */
    private function scrub(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            $text = str_replace($secret, '[redacted]', $text);
        }

        return (string) preg_replace('#(://)[^/@\s]+@#', '$1[redacted]@', $text);
    }
}
