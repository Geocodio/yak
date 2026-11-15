<?php

use App\DataTransferObjects\YakToolCall;
use App\Enums\ThreadKind;
use App\Enums\ThreadVisibility;
use App\Exceptions\StackRuleViolation;
use App\Exceptions\ThreadActionRefused;
use App\Jobs\StartChildThreadJob;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Stacks\ChildSpawner;
use App\Services\Stacks\StackActions;
use App\Services\Stacks\StackToolRouter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

const BUSY_SENTENCE = 'Yak is still handling another request for this thread. Try again in a moment.';

function teamRoot(): Thread
{
    return Thread::factory()->create(['repo' => 'example/api', 'visibility' => ThreadVisibility::Team, 'owner_user_id' => User::factory()->create()->id]);
}

function addChildOf(Thread $root, User $actor, string $key, string $name = 'scout', string $brief = 'Look around.', ?string $model = null): Thread
{
    return app(StackActions::class)->addChild($root, $actor, $name, $brief, ThreadKind::Research, $model, $key);
}

/**
 * Makes the parent row's locked select run out of lock wait while `$state->busy` is true.
 */
function holdParentLockBusy(Thread $parent): object
{
    $state = (object) ['busy' => true];
    Thread::retrieved(function (Thread $model) use ($parent, $state): void {
        if ($state->busy && $model->is($parent) && DB::transactionLevel() > 1) {
            $exception = new QueryException('mysql', 'select 1', [], new PDOException('Lock wait timeout exceeded'));
            $exception->errorInfo = [null, 1205, 'Lock wait timeout exceeded'];

            throw $exception;
        }
    });

    return $state;
}

it('creates one child for a repeated key', function () {
    Queue::fake();
    $root = teamRoot();
    $actor = User::factory()->create();
    $key = '3f6d2c1a-5b7e-4d89-a1c2-9e8f7a6b5c4d';

    $first = addChildOf($root, $actor, $key);
    // The first start ran: the child has a run, so a repeat has nothing left to start.
    $task = YakTask::query()->where('thread_id', $first->id)->firstOrFail();
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $first->id]);
    $second = addChildOf($root, $actor, $key);

    expect($second->is($first))->toBeTrue()
        ->and($first->spawn_key)->toBe($key)
        ->and(Thread::query()->where('parent_thread_id', $root->id)->count())->toBe(1);
    Queue::assertPushed(StartChildThreadJob::class, 1);
    Queue::assertPushed(StartChildThreadJob::class, fn (StartChildThreadJob $job): bool => $job->afterCommit === true);
    expect(DB::table('thread_events')->where('thread_id', $root->id)->where('type', 'child')->count())->toBe(1);
});

it('turns an input refusal into a validation error, never a 500', function () {
    $root = teamRoot();
    $actor = User::factory()->create();

    expect(fn () => addChildOf($root, $actor, '3f6d2c1a-5b7e-4d89-a1c2-9e8f7a6b5c4d', model: 'not-a-model'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toBe(['model' => ['Choose one of the listed models.']]));
    expect(fn () => addChildOf($root, $actor, '4f6d2c1a-5b7e-4d89-a1c2-9e8f7a6b5c4d', brief: '   '))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toBe(['brief' => ['Write what the sub-thread should do.']]));

    $this->mock(ChildSpawner::class)->shouldReceive('spawn')->andThrow(new StackRuleViolation('x'));
    Log::spy();

    expect(fn () => addChildOf($root, $actor, '5f6d2c1a-5b7e-4d89-a1c2-9e8f7a6b5c4d'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toBe(['stack' => ['Yak could not add this sub-thread. Try again.']]));
    Log::shouldHaveReceived('warning')->once();
});

it('drops a repeat while the first start job is still pending', function () {
    config(['queue.default' => 'database']);
    $root = teamRoot();
    $actor = User::factory()->create();
    $key = '3f6d2c1a-5b7e-4d89-a1c2-9e8f7a6b5c4d';

    $child = addChildOf($root, $actor, $key);
    addChildOf($root, $actor, $key);

    expect(DB::table('jobs')->count())->toBe(1);

    Cache::lock('laravel_unique_job:' . StartChildThreadJob::class . ':' . $child->id)->forceRelease();
    addChildOf($root, $actor, $key);

    expect(DB::table('jobs')->count())->toBe(2);
});

it('restarts a repeat whose first dispatch was lost', function () {
    Queue::fake();
    $root = teamRoot();
    $actor = User::factory()->create();
    $key = '3f6d2c1a-5b7e-4d89-a1c2-9e8f7a6b5c4d';
    $child = addChildOf($root, $actor, $key);
    Queue::fake();

    addChildOf($root, $actor, $key);
    Queue::assertPushed(StartChildThreadJob::class, 1);

    $task = YakTask::query()->where('thread_id', $child->id)->firstOrFail();
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $child->id]);
    Queue::fake();

    addChildOf($root, $actor, $key);
    Queue::assertNothingPushed();
});

it('refuses a key used by another person', function () {
    Queue::fake();
    $root = teamRoot();
    $key = '3f6d2c1a-5b7e-4d89-a1c2-9e8f7a6b5c4d';
    addChildOf($root, User::factory()->create(), $key);

    expect(fn () => addChildOf($root, User::factory()->create(), $key))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toBe(['send_key' => ['This key was already used for another request. Try again with a new one.']]));
    expect(Thread::query()->where('parent_thread_id', $root->id)->count())->toBe(1);
});

/**
 * SQLite cannot run two requests at once, so this proves the order that makes the cap hold: the parent row is
 * locked first, and the count of children runs after it inside the same transaction. The MariaDB case below
 * holds the real lock.
 */
it('never passes the cap when two adds race', function () {
    Queue::fake();
    $root = teamRoot();
    $actor = User::factory()->create();
    Thread::factory()->child($root)->count(10)->create();
    addChildOf($root, $actor, '1f6d2c1a-5b7e-4d89-a1c2-9e8f7a6b5c4d');

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = ['sql' => $query->sql, 'level' => DB::transactionLevel()];
    });
    $twelfth = addChildOf($root, $actor, '2f6d2c1a-5b7e-4d89-a1c2-9e8f7a6b5c4d');

    $lockedSelect = collect($queries)->search(fn (array $query): bool => str_starts_with($query['sql'], 'select * from "threads" where "threads"."id" = ?') || str_starts_with($query['sql'], 'select * from `threads` where `threads`.`id` = ?'));
    $count = collect($queries)->search(fn (array $query): bool => str_contains($query['sql'], 'count(*)') && str_contains($query['sql'], 'parent_thread_id'));

    expect($lockedSelect)->not->toBeFalse()
        ->and($count)->toBeGreaterThan($lockedSelect)
        ->and($queries[$count]['level'])->toBeGreaterThan(1)
        ->and($twelfth->exists)->toBeTrue()
        ->and(fn () => addChildOf($root, $actor, '3a6d2c1a-5b7e-4d89-a1c2-9e8f7a6b5c4d'))->toThrow(ThreadActionRefused::class)
        ->and(Thread::query()->where('parent_thread_id', $root->id)->count())->toBe(12);
});

it('waits on a real root lock and repeats safely', function () {
    Queue::fake();
    $root = teamRoot();
    $actor = User::factory()->create();
    DB::commit();

    $holder = function (int $rootId, int $seconds, bool $createsChild): mixed {
        $code = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); '
            . 'DB::beginTransaction(); DB::table("threads")->where("id", ' . $rootId . ')->lockForUpdate()->first(); '
            . ($createsChild ? 'DB::table("threads")->insert(["public_id" => "holdchld", "name" => "holder", "title" => "Holder", "repo" => "example/api", "kind" => "implement", "parent_thread_id" => ' . $rootId . ', "root_thread_id" => ' . $rootId . ', "created_at" => now(), "updated_at" => now()]); ' : '')
            . 'fwrite(STDERR, "locked\\n"); sleep(' . $seconds . '); DB::commit();';
        $process = Process::path(base_path())->start([PHP_BINARY, '-r', $code]);

        for ($waited = 0; $waited < 100 && $process->running() && ! str_contains($process->errorOutput(), 'locked'); $waited++) {
            usleep(100_000);
        }
        expect($process->errorOutput())->toContain('locked');

        return $process;
    };

    try {
        $process = $holder($root->id, 8, false);
        $startedAt = microtime(true);
        expect(fn () => addChildOf($root, $actor, '3f6d2c1a-5b7e-4d89-a1c2-9e8f7a6b5c4d'))
            ->toThrow(fn (HttpException $exception) => expect($exception->getStatusCode())->toBe(503)->and($exception->getMessage())->toBe(BUSY_SENTENCE));
        expect(microtime(true) - $startedAt)->toBeBetween(4, 7)
            ->and(Thread::query()->where('spawn_key', '3f6d2c1a-5b7e-4d89-a1c2-9e8f7a6b5c4d')->exists())->toBeFalse();
        $process->wait();

        $child = addChildOf($root, $actor, '3f6d2c1a-5b7e-4d89-a1c2-9e8f7a6b5c4d');
        expect($child->wasRecentlyCreated)->toBeTrue()
            ->and(addChildOf($root, $actor, '3f6d2c1a-5b7e-4d89-a1c2-9e8f7a6b5c4d')->is($child))->toBeTrue()
            ->and(Thread::query()->where('spawn_key', '3f6d2c1a-5b7e-4d89-a1c2-9e8f7a6b5c4d')->count())->toBe(1);

        expect(config('yak.threads.max_children'))->toBe(12);
        Thread::factory()->child($root)->count(10)->create();
        $process = $holder($root->id, 2, true);
        expect(fn () => addChildOf($root, $actor, '4f6d2c1a-5b7e-4d89-a1c2-9e8f7a6b5c4d'))
            ->toThrow(fn (ThreadActionRefused $refusal) => expect($refusal->errorCode)->toBe('stack_full')->and($refusal->details['count'])->toBe(12));
        $process->wait();
        expect(Thread::query()->where('parent_thread_id', $root->id)->count())->toBe(12);
    } finally {
        Thread::query()->where('root_thread_id', $root->id)->delete();
        YakTask::query()->whereNotNull('thread_id')->whereDoesntHave('thread')->delete();
        $root->delete();
    }
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'mariadb', 'needs the MariaDB row lock');

it('answers busy when the parent lock wait runs out', function () {
    Queue::fake();
    $root = teamRoot();
    holdParentLockBusy($root);

    expect(fn () => addChildOf($root, User::factory()->create(), '3f6d2c1a-5b7e-4d89-a1c2-9e8f7a6b5c4d'))
        ->toThrow(fn (HttpException $exception) => expect($exception->getStatusCode())->toBe(503)->and($exception->getMessage())->toBe(BUSY_SENTENCE));
    expect(Thread::query()->where('parent_thread_id', $root->id)->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

it('tells Claude when the stack is busy', function () {
    $root = teamRoot();
    holdParentLockBusy($root);

    $result = app(StackToolRouter::class)->handle($root, new YakToolCall('call-1', 'spawn_thread', ['name' => 'scout', 'brief' => 'Look.', 'kind' => 'research']));

    expect($result->isError)->toBeTrue()
        ->and($result->content)->toBe('Yak is busy with another change to this stack. Try again in a moment.');
});

it('answers the public id and person sentences on the web', function () {
    Queue::fake();
    $user = User::factory()->create();
    $root = teamRoot();

    $response = $this->actingAs($user)->postJson(route('threads.stack.children.store', $root), ['name' => 'Scout', 'brief' => 'Look around.', 'kind' => 'research'])->assertCreated();
    expect($response->json('id'))->toBe(Thread::query()->where('parent_thread_id', $root->id)->sole()->public_id);

    $root->forceFill(['stack_stopped_at' => now()])->save();
    $this->actingAs($user)->postJson(route('threads.stack.children.store', $root), ['name' => 'Scout', 'brief' => 'Look around.', 'kind' => 'research'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This stack was stopped, so it cannot start sub-threads.')
        ->assertJsonPath('errors.stack.0', 'This stack was stopped, so it cannot start sub-threads.');
});
