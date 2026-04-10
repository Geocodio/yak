<?php

use App\Enums\TaskStatus;
use ArtisanBuild\FatEnums\StateMachine\InvalidStateTransition;

test('default status is Pending', function () {
    expect(TaskStatus::DEFAULT)->toBe(TaskStatus::Pending);
});

/*
|--------------------------------------------------------------------------
| Valid Transitions (every row in the transition table)
|--------------------------------------------------------------------------
*/

test('Pending can transition to Running', function () {
    expect(TaskStatus::Pending->canTransitionTo(TaskStatus::Running))->toBeTrue();
});

test('Running can transition to AwaitingCi', function () {
    expect(TaskStatus::Running->canTransitionTo(TaskStatus::AwaitingCi))->toBeTrue();
});

test('Running can transition to AwaitingClarification', function () {
    expect(TaskStatus::Running->canTransitionTo(TaskStatus::AwaitingClarification))->toBeTrue();
});

test('Running can transition to Success', function () {
    expect(TaskStatus::Running->canTransitionTo(TaskStatus::Success))->toBeTrue();
});

test('Running can transition to Failed', function () {
    expect(TaskStatus::Running->canTransitionTo(TaskStatus::Failed))->toBeTrue();
});

test('AwaitingClarification can transition to Running', function () {
    expect(TaskStatus::AwaitingClarification->canTransitionTo(TaskStatus::Running))->toBeTrue();
});

test('AwaitingClarification can transition to Expired', function () {
    expect(TaskStatus::AwaitingClarification->canTransitionTo(TaskStatus::Expired))->toBeTrue();
});

test('AwaitingCi can transition to Success', function () {
    expect(TaskStatus::AwaitingCi->canTransitionTo(TaskStatus::Success))->toBeTrue();
});

test('AwaitingCi can transition to Retrying', function () {
    expect(TaskStatus::AwaitingCi->canTransitionTo(TaskStatus::Retrying))->toBeTrue();
});

test('AwaitingCi can transition to Failed', function () {
    expect(TaskStatus::AwaitingCi->canTransitionTo(TaskStatus::Failed))->toBeTrue();
});

test('Retrying can transition to AwaitingCi', function () {
    expect(TaskStatus::Retrying->canTransitionTo(TaskStatus::AwaitingCi))->toBeTrue();
});

test('Retrying can transition to Failed', function () {
    expect(TaskStatus::Retrying->canTransitionTo(TaskStatus::Failed))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Invalid Transitions
|--------------------------------------------------------------------------
*/

test('Pending cannot transition to AwaitingCi', function () {
    expect(TaskStatus::Pending->canTransitionTo(TaskStatus::AwaitingCi))->toBeFalse();
});

test('Pending cannot transition to Success', function () {
    expect(TaskStatus::Pending->canTransitionTo(TaskStatus::Success))->toBeFalse();
});

test('transitionTo throws InvalidStateTransition for invalid transition', function () {
    TaskStatus::Pending->transitionTo(TaskStatus::AwaitingCi);
})->throws(InvalidStateTransition::class);

test('transitionTo throws InvalidStateTransition from terminal state', function () {
    TaskStatus::Success->transitionTo(TaskStatus::Running);
})->throws(InvalidStateTransition::class);

/*
|--------------------------------------------------------------------------
| Terminal States (Success, Failed, Expired) reject all outbound transitions
|--------------------------------------------------------------------------
*/

test('Success is a final state', function () {
    expect(TaskStatus::Success->isFinal())->toBeTrue();
});

test('Failed is a final state', function () {
    expect(TaskStatus::Failed->isFinal())->toBeTrue();
});

test('Expired is a final state', function () {
    expect(TaskStatus::Expired->isFinal())->toBeTrue();
});

test('Success rejects all outbound transitions', function () {
    foreach (TaskStatus::cases() as $state) {
        expect(TaskStatus::Success->canTransitionTo($state))->toBeFalse();
    }
});

test('Failed rejects all outbound transitions', function () {
    foreach (TaskStatus::cases() as $state) {
        expect(TaskStatus::Failed->canTransitionTo($state))->toBeFalse();
    }
});

test('Expired rejects all outbound transitions', function () {
    foreach (TaskStatus::cases() as $state) {
        expect(TaskStatus::Expired->canTransitionTo($state))->toBeFalse();
    }
});

test('non-final states are not final', function () {
    $nonFinalStates = [
        TaskStatus::Pending,
        TaskStatus::Running,
        TaskStatus::AwaitingClarification,
        TaskStatus::AwaitingCi,
        TaskStatus::Retrying,
    ];

    foreach ($nonFinalStates as $state) {
        expect($state->isFinal())->toBeFalse();
    }
});

test('transitionTo returns the target state on valid transition', function () {
    $result = TaskStatus::Pending->transitionTo(TaskStatus::Running);

    expect($result)->toBe(TaskStatus::Running);
});
