<?php

use App\DataTransferObjects\CiFailureQualification;
use App\Models\CiJobFailure;
use App\Models\Repository;
use App\Services\CiFailure\CiFailureTaskCreator;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 99);

    $this->repository = Repository::factory()->create([
        'slug' => 'acme/widgets',
        'github_full_name' => 'acme/widgets',
        'default_branch' => 'main',
    ]);
});

it('keeps the task context under the column limit for large logs on many branches', function () {
    $evidence = collect(range(1, 11))->map(fn (int $index): CiJobFailure => CiJobFailure::factory()->create([
        'branch' => "feature/{$index}",
        'job_id' => 9000 + $index,
        'failed_at' => now()->subMinutes(20 - $index),
    ]));

    $excerpt = str_repeat("line of log output\n", 1100);
    $gathered = ['excerpts' => $evidence->mapWithKeys(fn (CiJobFailure $row): array => [$row->job_id => $excerpt])->all(), 'author' => null];

    $task = app(CiFailureTaskCreator::class)->create($this->repository, new CiFailureQualification(true, 'cross_branch', $evidence), $gathered);

    $context = json_decode($task->context, true);

    expect(strlen($task->context))->toBeLessThan(60000)
        ->and($context['failures'])->toHaveCount(11)
        ->and(collect($context['failures'])->filter(fn (array $failure): bool => $failure['log_excerpt'] !== null)->count())->toBeLessThanOrEqual(4)
        ->and($task->description)->toBe('Fix CI: Test (Main) failing on 11 branches');
});

it('caps an excerpt from its tail and keeps only the newest failure per branch', function () {
    $older = CiJobFailure::factory()->create(['branch' => 'feature/a', 'job_id' => 1, 'failed_at' => now()->subHour()]);
    $newer = CiJobFailure::factory()->create(['branch' => 'feature/a', 'job_id' => 2, 'failed_at' => now()]);
    $other = CiJobFailure::factory()->create(['branch' => 'feature/b', 'job_id' => 3, 'failed_at' => now()->subMinute()]);

    $gathered = ['excerpts' => [2 => str_repeat('x', 20000) . 'THE END', 3 => 'short'], 'author' => null];

    $task = app(CiFailureTaskCreator::class)->create($this->repository, new CiFailureQualification(true, 'cross_branch', collect([$older, $newer, $other])), $gathered);
    $failures = json_decode($task->context, true)['failures'];

    expect($failures)->toHaveCount(2)
        ->and(strlen($failures[0]['log_excerpt']))->toBeLessThanOrEqual(8000)
        ->and($failures[0]['log_excerpt'])->toEndWith('THE END')
        ->and($task->description)->toBe('Fix CI: Test (Main) failing on 2 branches');
});
