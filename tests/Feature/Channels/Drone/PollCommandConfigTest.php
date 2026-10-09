<?php

use App\Jobs\ProcessCIResultJob;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('polls Drone for a repository whose .yak/config.yml says drone', function () {
    Queue::fake();
    config([
        'yak.channels.drone.url' => 'https://drone.example.com',
        'yak.channels.drone.token' => 'test-token',
    ]);
    $repository = Repository::factory()->create(['slug' => 'acme/app', 'ci_system' => 'github_actions']);
    $task = YakTask::factory()->awaitingCi()->create(['repo' => $repository->slug, 'branch_name' => 'yak/task-1']);
    fakeYakFiles(['config.yml' => "version: 1\nci: drone\n"]);
    Http::fake([
        'drone.example.com/api/repos/acme/app/builds*' => Http::response([
            ['number' => 100, 'status' => 'success', 'started' => now()->timestamp, 'link' => 'https://drone.example.com/acme/app/100'],
        ]),
    ]);

    $this->artisan('yak:poll-drone-ci')->assertSuccessful();

    Queue::assertPushed(ProcessCIResultJob::class, fn (ProcessCIResultJob $job) => $job->task->id === $task->id);
});
