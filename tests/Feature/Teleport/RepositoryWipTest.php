<?php

use App\DataTransferObjects\WipResult;
use App\Enums\RepositoryRole;
use App\Exceptions\SandboxCommandFailed;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Teleport\RepositoryWip;
use App\Services\Threads\FakeSandboxManager;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * A fake sandbox that answers git like an extra checkout with scripted dirtiness.
 */
class RepositoryWipSandbox extends FakeSandboxManager
{
    public bool $isDirty = true;

    public int $ahead = 0;

    public bool $pushFails = false;

    public function exec(Thread $thread, array $command, ?string $stdin = null, array $env = []): ProcessResult
    {
        parent::exec($thread, $command, $stdin, $env);
        $line = implode(' ', $command);

        return match (true) {
            str_contains($line, 'status --porcelain') => Process::result(output: $this->isDirty ? " M app.php\n" : ''),
            str_contains($line, 'rev-list') => Process::result(output: "{$this->ahead}\n"),
            str_contains($line, 'rev-parse HEAD') => Process::result(output: "abc123\n"),
            str_contains($line, 'remote get-url') => Process::result(output: "https://x:secret@github.com/acme/api.git\n"),
            $this->pushFails && str_contains($line, ' push ') => Process::result(errorOutput: 'rejected https://x:secret@github.com/acme/api.git', exitCode: 1),
            default => Process::result(),
        };
    }
}

beforeEach(function () {
    $this->thread = Thread::factory()->paused()->create(['repo' => 'acme/widgets', 'git_author_name' => 'Ada', 'git_author_email' => 'ada@example.com']);
    $this->sandboxes = new RepositoryWipSandbox;
    $this->extra = ThreadRepository::factory()->role(RepositoryRole::Changes)->create([
        'thread_id' => $this->thread->id, 'repo' => 'acme/api', 'sandbox_path' => '/extras/api', 'branch' => 'yak/x',
    ]);
    $this->commands = fn (): array => collect($this->sandboxes->calls)->where('operation', 'exec')->map(fn (array $call): string => implode(' ', $call['arguments']['command']))->all();
});

it('commits a dirty extra with the person identity and the Yak trailer', function () {
    $result = (new RepositoryWip($this->sandboxes))->preserve($this->thread, $this->extra);

    expect($result)->toEqual(new WipResult(true, true, 'abc123'));
    $commands = ($this->commands)();
    expect($commands)->toContain('git -C /extras/api add -A')
        ->toContain("git -C /extras/api -c user.name=Ada -c user.email=ada@example.com commit -m WIP: teleport to local machine\n\nCo-authored-by: Yak <yak@geocod.io>")
        ->toContain('git -C /extras/api push origin HEAD:refs/heads/yak/x');
});

it('does nothing for a clean extra with nothing ahead', function () {
    $this->sandboxes->isDirty = false;

    expect((new RepositoryWip($this->sandboxes))->preserve($this->thread, $this->extra))->toEqual(new WipResult(false, false, null));
    expect(($this->commands)())->not->toContain('git -C /extras/api add -A');
});

it('pushes a clean extra that is ahead without committing', function () {
    $this->sandboxes->isDirty = false;
    $this->sandboxes->ahead = 2;

    expect((new RepositoryWip($this->sandboxes))->preserve($this->thread, $this->extra))->toEqual(new WipResult(false, true, 'abc123'));
});

it('never runs git for a context repo', function () {
    $this->extra->update(['role' => RepositoryRole::Context]);

    expect((new RepositoryWip($this->sandboxes))->preserve($this->thread, $this->extra->refresh()))->toEqual(new WipResult(false, false, null));
    expect($this->sandboxes->calls)->toBe([]);
});

it('redacts remote urls from a push failure', function () {
    $this->sandboxes->pushFails = true;

    try {
        (new RepositoryWip($this->sandboxes))->preserve($this->thread, $this->extra);
        $this->fail('expected a failure');
    } catch (SandboxCommandFailed $exception) {
        expect($exception->getMessage())->toContain('push failed')->not->toContain('secret')->not->toContain('github.com');
    }
});
