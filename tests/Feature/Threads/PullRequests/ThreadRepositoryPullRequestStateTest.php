<?php

use App\Enums\RepositoryRole;
use App\Models\ThreadRepository;

it('reports an open pull request only for the open state', function (?string $state, bool $expected) {
    $row = ThreadRepository::factory()->create(['pull_request_state' => $state]);

    expect($row->hasOpenPullRequest())->toBe($expected);
})->with([
    'open' => ['open', true],
    'merged' => ['merged', false],
    'closed' => ['closed', false],
    'none' => [null, false],
]);

it('reports an ended pull request for merged and closed', function (?string $state, bool $expected) {
    $row = ThreadRepository::factory()->create(['pull_request_state' => $state]);

    expect($row->hasEnded())->toBe($expected);
})->with([
    'open' => ['open', false],
    'merged' => ['merged', true],
    'closed' => ['closed', true],
    'none' => [null, false],
]);

it('treats primary and changes rows as changes repos and context as not', function () {
    expect(ThreadRepository::factory()->create()->isChangesRepo())->toBeTrue()
        ->and(ThreadRepository::factory()->changes()->create()->isChangesRepo())->toBeTrue()
        ->and(ThreadRepository::factory()->context()->create()->isChangesRepo())->toBeFalse();
});

it('scopes to open pull requests and casts checks', function () {
    $open = ThreadRepository::factory()->withPullRequest(4)->create(['ci_checks' => [['name' => 'lint', 'state' => 'passing']]]);
    ThreadRepository::factory()->withPullRequest(5, 'merged')->create();

    expect(ThreadRepository::query()->openPullRequests()->pluck('id')->all())->toBe([$open->id])
        ->and($open->fresh()->ci_checks)->toBe([['name' => 'lint', 'state' => 'passing']])
        ->and($open->fresh()->role)->toBe(RepositoryRole::Primary);
});
