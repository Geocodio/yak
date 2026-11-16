<?php

use App\Enums\RestoreStepName;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRestoreStep;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

test('repository build credentials are encrypted at rest and hidden from arrays', function () {
    $repository = Repository::factory()->create(['build_credentials' => ['NPM_TOKEN' => 'secret-value']]);

    $raw = DB::table('repositories')->where('id', $repository->id)->value('build_credentials');

    expect($raw)->not->toContain('secret-value')
        ->and($repository->fresh()->build_credentials)->toBe(['NPM_TOKEN' => 'secret-value'])
        ->and($repository->fresh()->toArray())->not->toHaveKey('build_credentials');
});

test('thread restore steps are unique per thread and step', function () {
    $thread = Thread::factory()->create();
    ThreadRestoreStep::factory()->for($thread)->create(['step' => RestoreStepName::CheckoutBranch, 'position' => 2]);

    expect(fn () => ThreadRestoreStep::factory()->for($thread)->failed()->create(['step' => RestoreStepName::CheckoutBranch, 'position' => 2]))
        ->toThrow(UniqueConstraintViolationException::class);
    expect($thread->restoreSteps()->count())->toBe(1);
});
