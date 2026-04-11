<?php

use App\Jobs\ClarificationReplyJob;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\ResearchYakJob;
use App\Jobs\RetryYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\SetupYakJob;
use App\Models\DailyCost;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| EnsureDailyBudget Middleware
|--------------------------------------------------------------------------
*/

test('allows job when daily cost is under budget', function () {
    config()->set('yak.daily_budget_usd', 50.00);

    DailyCost::factory()->create([
        'date' => now()->toDateString(),
        'total_usd' => 25.00,
    ]);

    $called = false;
    $middleware = new EnsureDailyBudget;
    $job = new class
    {
        public bool $failed = false;

        public function fail(Throwable $e): void
        {
            $this->failed = true;
        }
    };

    $middleware->handle($job, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeTrue()
        ->and($job->failed)->toBeFalse();
});

test('blocks job when daily cost exceeds budget', function () {
    config()->set('yak.daily_budget_usd', 50.00);

    DailyCost::factory()->create([
        'date' => now()->toDateString(),
        'total_usd' => 55.00,
    ]);

    $called = false;
    $middleware = new EnsureDailyBudget;
    $job = new class
    {
        public bool $failed = false;

        public ?string $failMessage = null;

        public function fail(Throwable $e): void
        {
            $this->failed = true;
            $this->failMessage = $e->getMessage();
        }
    };

    $middleware->handle($job, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeFalse()
        ->and($job->failed)->toBeTrue()
        ->and($job->failMessage)->toContain('Daily budget');
});

test('blocks job when daily cost equals budget exactly', function () {
    config()->set('yak.daily_budget_usd', 50.00);

    DailyCost::factory()->create([
        'date' => now()->toDateString(),
        'total_usd' => 50.00,
    ]);

    $called = false;
    $middleware = new EnsureDailyBudget;
    $job = new class
    {
        public bool $failed = false;

        public function fail(Throwable $e): void
        {
            $this->failed = true;
        }
    };

    $middleware->handle($job, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeFalse()
        ->and($job->failed)->toBeTrue();
});

test('allows job when no daily cost record exists', function () {
    config()->set('yak.daily_budget_usd', 50.00);

    $called = false;
    $middleware = new EnsureDailyBudget;
    $job = new class
    {
        public bool $failed = false;

        public function fail(Throwable $e): void
        {
            $this->failed = true;
        }
    };

    $middleware->handle($job, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeTrue()
        ->and($job->failed)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| DailyCost::accumulate
|--------------------------------------------------------------------------
*/

test('accumulate creates daily cost record when none exists', function () {
    DailyCost::accumulate(2.50);

    $record = DailyCost::whereDate('date', now()->toDateString())->first();

    expect($record)->not->toBeNull()
        ->and((float) $record->total_usd)->toBe(2.5)
        ->and($record->task_count)->toBe(1);
});

test('accumulate increments existing daily cost record', function () {
    DailyCost::factory()->create([
        'date' => now()->toDateString(),
        'total_usd' => 10.00,
        'task_count' => 3,
    ]);

    DailyCost::accumulate(2.50);

    $record = DailyCost::whereDate('date', now()->toDateString())->first();

    expect((float) $record->total_usd)->toBe(12.5)
        ->and($record->task_count)->toBe(4);
});

/*
|--------------------------------------------------------------------------
| Job Middleware Registration
|--------------------------------------------------------------------------
*/

test('all claude jobs include EnsureDailyBudget middleware', function () {
    $repository = Repository::factory()->create(['slug' => 'test-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'test-repo']);

    $jobs = [
        new RunYakJob($task),
        new RetryYakJob($task),
        new SetupYakJob($task),
        new ResearchYakJob($task),
        new ClarificationReplyJob($task, 'reply'),
    ];

    foreach ($jobs as $job) {
        $middlewareClasses = array_map(fn ($m) => $m::class, $job->middleware());
        expect($middlewareClasses)->toContain(EnsureDailyBudget::class);
    }
});

/*
|--------------------------------------------------------------------------
| Cost Accumulation in Jobs
|--------------------------------------------------------------------------
*/

test('successful run accumulates daily cost', function () {
    Process::fake([
        'docker-compose stop' => Process::result(''),
        'lsof *' => Process::result(''),
        'git fetch *' => Process::result(''),
        'git checkout -b *' => Process::result(''),
        'git checkout *' => Process::result(''),
        'claude *' => Process::result(json_encode([
            'result' => 'Done',
            'cost_usd' => 3.25,
            'session_id' => 'sess_1',
            'num_turns' => 10,
            'duration_ms' => 60000,
            'is_error' => false,
        ])),
        'git push *' => Process::result(''),
    ]);

    Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'test-repo', 'source' => 'slack']);

    $job = new RunYakJob($task);
    $job->handle();

    $record = DailyCost::whereDate('date', now()->toDateString())->first();

    expect($record)->not->toBeNull()
        ->and((float) $record->total_usd)->toBe(3.25)
        ->and($record->task_count)->toBe(1);
});
