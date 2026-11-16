<?php

use App\Channels\GitHub\AppService;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\PullRequests\CiCheckDetails;
use App\Services\Threads\PullRequests\RepositoryCiRetry;
use Illuminate\Support\Facades\Queue;

it('marks the failing check with a clipped excerpt and keeps passing checks', function () {
    $output = "## tests (https://github.com/acme/web/runs/1)\n\n**Failed**\n\n" . str_repeat('x', 800) . "\n\n---\n\n## lint (https://github.com/acme/web/runs/2)\n\nbad style";
    $runs = [
        ['name' => 'tests', 'conclusion' => 'failure', 'durationSeconds' => 160],
        ['name' => 'lint', 'conclusion' => 'failure', 'durationSeconds' => 28],
        ['name' => 'build', 'conclusion' => 'success', 'durationSeconds' => 58],
        ['name' => 'deploy', 'conclusion' => null, 'durationSeconds' => null],
    ];

    $checks = CiCheckDetails::build($runs, $output);

    expect(collect($checks)->pluck('state', 'name')->all())->toBe(['tests' => 'failing', 'lint' => 'failing', 'build' => 'passing', 'deploy' => 'running'])
        ->and(mb_strlen($checks[0]['excerpt']))->toBeLessThanOrEqual(500)->and($checks[0]['excerpt'])->toStartWith('**Failed**')
        ->and($checks[1]['excerpt'])->toBe('bad style')->and($checks[2]['excerpt'])->toBeNull()
        ->and($checks[0]['durationSeconds'])->toBe(160);
});

it('names failing checks from the output alone when check runs are unavailable', function () {
    $checks = CiCheckDetails::build([], "## tests (https://x/1)\n\nboom");

    expect($checks)->toBe([['name' => 'tests', 'state' => 'failing', 'durationSeconds' => null, 'excerpt' => 'boom']]);
});

it('collects check runs through the GitHub app service', function () {
    config()->set('yak.channels.github.installation_id', 7);
    $github = Mockery::mock(AppService::class);
    $github->shouldReceive('getCheckRunSummaries')->with(7, 'acme/web', 'abc')->andReturn([['name' => 'build', 'conclusion' => 'success', 'durationSeconds' => 5]]);

    expect(CiCheckDetails::collect($github, 'acme/web', 'abc', null))->toBe([['name' => 'build', 'state' => 'passing', 'durationSeconds' => 5, 'excerpt' => null]]);
});

it('stores check details on the thread repository row with a ci result', function () {
    Queue::fake();
    $row = ThreadRepository::factory()->for(Thread::factory())->changes()->withPullRequest(4)->create(['repo' => 'acme/web', 'branch' => 'yak/x']);
    $checks = CiCheckDetails::build([['name' => 'build', 'conclusion' => 'success', 'durationSeconds' => 5], ['name' => 'tests', 'conclusion' => 'failure', 'durationSeconds' => 9]], "## tests (https://x/1)\n\nboom");

    app(RepositoryCiRetry::class)->handleResult($row, false, 'boom', 'abc', $checks);

    $stored = $row->refresh()->ci_checks;
    expect($stored)->toHaveCount(2)->and($stored[1]['state'])->toBe('failing')->and($stored[1]['excerpt'])->toBe('boom')->and($stored[0]['state'])->toBe('passing');
});
