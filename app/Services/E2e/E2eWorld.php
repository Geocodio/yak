<?php

namespace App\Services\E2e;

use App\DataTransferObjects\ClarificationQuestion;
use App\Enums\DeviceKind;
use App\Enums\RepositoryRole;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Models\ClaudeAccount;
use App\Models\Device;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Devices\DeviceTokens;
use App\Services\Threads\ThreadStarter;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * The seeded world of the nightly journeys: one person, one repository, four threads and two paired phones.
 * Mirrors tests/Support/App/ChangesHelpers.php threadWithChanges() for the diff and tests/Support/ReplayFixture.php
 * for playing a recorded stream through the real thread driver.
 */
final class E2eWorld
{
    private const int MAX_PLAY_PASSES = 50;

    /** The wire and the container's BrokerStreams must point at the same Redis; the seed command resolves both on `threads`. */
    public function __construct(private readonly E2eBrokerWire $wire, private readonly E2eRunnerScript $script) {}

    /**
     * Creates the journeys' world on an empty database and returns it. Each finished turn is played the way the
     * fake runner plays it: its `start_runner` and `turn` commands go through E2eRunnerScript (pace 0) and
     * E2eBrokerWire onto the real `{channel}:out` stream, and `yak:thread-drivers --once` runs until they are
     * acknowledged. So the thread rows (runner state, generation, last handled sequence) and each channel's last
     * `:out` entry agree, and a fake runner started afterwards continues the generation and sequence from that
     * entry as a restarted broker does.
     *
     * @return array{
     *   url: string,
     *   person: string,
     *   phones: array{main: array{name: string, token: string}, second: array{name: string, token: string}},
     *   threads: array{
     *     finished: array{id: string, title: string},
     *     question: array{id: string, title: string},
     *     held: array{id: string, title: string},
     *     changes: array{id: string, title: string}
     *   }
     * }
     */
    public function create(string $url): array
    {
        $previousGrace = config('yak.threads.turn_grace_seconds');
        $previousQueue = config('queue.default');
        // A turn ends at once instead of after the quiet window, and the jobs of the first turns run in this process.
        config(['yak.threads.turn_grace_seconds' => 0, 'queue.default' => 'sync']);

        try {
            return $this->build($url);
        } finally {
            config(['yak.threads.turn_grace_seconds' => $previousGrace, 'queue.default' => $previousQueue]);
        }
    }

    /**
     * @return array{
     *   url: string,
     *   person: string,
     *   phones: array{main: array{name: string, token: string}, second: array{name: string, token: string}},
     *   threads: array{
     *     finished: array{id: string, title: string},
     *     question: array{id: string, title: string},
     *     held: array{id: string, title: string},
     *     changes: array{id: string, title: string}
     *   }
     * }
     */
    private function build(string $url): array
    {
        $person = User::factory()->create(['name' => 'Test Person', 'email' => 'test-person@example.com']);

        Repository::factory()->default()->create([
            'slug' => 'example/api',
            'name' => 'API',
            'github_full_name' => 'example/api',
            'git_url' => 'https://github.com/example/api.git',
            'ci_system' => 'github_actions',
        ]);
        ClaudeAccount::factory()->system()->default()->create(['name' => 'Shared account', 'validated_at' => now()]);

        $phones = [
            'main' => $this->pair($person, 'Test phone'),
            'second' => $this->pair($person, 'Second test phone'),
        ];

        $finished = $this->finishedThread($person, 'Retry failed uploads');

        $question = $this->finishedThread($person, 'Pick a rule for failed rows');
        // The finished task cannot move back to asking, so the open round belongs to the thread's next task.
        $asking = YakTask::factory()->create([
            'thread_id' => $question->id,
            'repo' => 'example/api',
            'description' => $question->title,
            'status' => TaskStatus::AwaitingClarification,
            'started_by_user_id' => $person->id,
            'clarification_expires_at' => now()->addDays(30),
        ]);
        $asking->recordClarificationRound([
            $this->question([
                'id' => 'on_failure',
                'header' => 'On failure',
                'question' => 'What should happen to a row that fails?',
                'options' => [['label' => 'Mark the row as failed'], ['label' => 'Fail the whole batch']],
                'multi_select' => false,
            ]),
        ], 'I need one decision.');

        $held = $this->finishedThread($person, 'Look at the importer');
        $held->forceFill(['sandbox_state' => ThreadSandboxState::Running])->save();

        $changes = $this->finishedThread($person, 'Add an empty file check');
        $this->addChanges($changes);

        return [
            'url' => $url,
            'person' => $person->name,
            'phones' => $phones,
            'threads' => [
                'finished' => ['id' => $finished->public_id, 'title' => $finished->title],
                'question' => ['id' => $question->public_id, 'title' => $question->title],
                'held' => ['id' => $held->public_id, 'title' => $held->title],
                'changes' => ['id' => $changes->public_id, 'title' => $changes->title],
            ],
        ];
    }

    /**
     * @return array{name: string, token: string}
     */
    private function pair(User $person, string $name): array
    {
        $tokens = app(DeviceTokens::class);
        $token = $tokens->generate();

        Device::factory()->phone()->create([
            'user_id' => $person->id,
            'name' => $name,
            'kind' => DeviceKind::Phone,
            'token_hash' => $tokens->hash($token),
        ]);

        return ['name' => $name, 'token' => $token];
    }

    private function finishedThread(User $person, string $title): Thread
    {
        $thread = app(ThreadStarter::class)->start($person, 'example/api', TaskMode::Fix, $title, null, null);
        $this->playUntilFinished($thread);

        return $thread->refresh();
    }

    /**
     * Plays the fake runner for one thread: commands in, scripted lines out, drivers run, until the turn completed.
     */
    private function playUntilFinished(Thread $thread): void
    {
        for ($pass = 0; $pass < self::MAX_PLAY_PASSES; $pass++) {
            $isWritten = false;

            foreach ($this->wire->readCommands($this->wire->channels(), 10, 50) as $entry) {
                $command = $entry['command'];

                if ($command === null) {
                    continue;
                }

                $channel = $entry['channel'];
                $generation = ($command['type'] ?? null) === 'start_runner' ? $this->wire->startGeneration($channel) : $this->wire->generation($channel);

                foreach ($this->script->respond($channel, $command, $generation) as $scripted) {
                    $this->wire->write($channel, $scripted['line'], $scripted['fromBroker'], $generation);
                    $isWritten = true;
                }
            }

            Artisan::call('yak:thread-drivers', ['--once' => true]);

            $latest = TaskRun::query()->where('thread_id', $thread->id)->latest('id')->first();

            if (! $isWritten && $latest?->turn_state === TurnState::Completed) {
                return;
            }
        }

        throw new RuntimeException("The first turn of \"{$thread->title}\" did not finish in " . self::MAX_PLAY_PASSES . ' passes.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function question(array $attributes): ClarificationQuestion
    {
        return ClarificationQuestion::fromArray($attributes) ?? throw new RuntimeException('The seeded question is malformed.');
    }

    private function addChanges(Thread $thread): void
    {
        $branch = 'yak/add-an-empty-file-check';
        $thread->forceFill(['branch' => $branch])->save();
        ($thread->headTask() ?? throw new RuntimeException('The thread has no task.'))->update(['branch_name' => $branch]);
        ThreadRepository::query()->where('thread_id', $thread->id)->where('role', RepositoryRole::Primary)->update(['branch' => $branch]);

        // The finished turn already captured an empty diff; the world replaces it with two files.
        $diff = ThreadDiff::query()->updateOrCreate(['thread_id' => $thread->id], ['base_sha' => str_repeat('a', 40), 'head_sha' => str_repeat('b', 40), 'files_count' => 2, 'additions' => 10, 'deletions' => 5]);
        ThreadDiffFile::query()->where('thread_diff_id', $diff->id)->delete();
        ThreadDiffFile::factory()->create(['thread_diff_id' => $diff->id, 'path' => 'src/upload.php', 'additions' => 6, 'deletions' => 3]);
        ThreadDiffFile::factory()->create(['thread_diff_id' => $diff->id, 'path' => 'src/retry.php', 'additions' => 4, 'deletions' => 2]);
    }
}
