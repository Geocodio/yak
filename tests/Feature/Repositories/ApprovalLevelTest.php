<?php

use App\Models\Repository;
use App\Models\User;

it('defaults new repositories to level 1 and mode off', function () {
    $repository = Repository::factory()->create()->refresh();

    expect($repository->approvalLevel())->toBe(1)
        ->and($repository->approvalMode())->toBe('off');
});

it('records who changed the level and when', function () {
    $repository = Repository::factory()->create();
    $user = User::factory()->create();

    $repository->setApproval(4, 'enforce', $user);

    $repository->refresh();
    expect($repository->approval_changed_by_user_id)->toBe($user->id)
        ->and($repository->approval_changed_at)->not->toBeNull()
        ->and($repository->approvalLevel())->toBe(4)
        ->and($repository->approvalMode())->toBe('enforce');
});

it('rejects a level outside 1 to 5', function () {
    Repository::factory()->create()->setApproval(6, 'shadow', null);
})->throws(InvalidArgumentException::class);

it('rejects an unknown mode', function () {
    Repository::factory()->create()->setApproval(3, 'off', null);
})->throws(InvalidArgumentException::class);

it('sets the level from the command', function () {
    $repository = Repository::factory()->create();

    $this->artisan('yak:approval-level', ['slug' => $repository->slug, 'level' => 5])->assertSuccessful();

    $repository->refresh();
    expect($repository->approvalLevel())->toBe(5)
        ->and($repository->approvalMode())->toBe('shadow');
});

it('ignores an approval block in config.yml', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['config.yml' => "version: 1\nreview:\n  approval:\n    mode: enforce\n"]);

    expect($repository->settings()->reviewPolicy()['mode'])->toBe('off')
        ->and($repository->approvalMode())->toBe('off');
});
