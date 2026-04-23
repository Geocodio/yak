<?php

use App\Enums\DeploymentStatus;
use ArtisanBuild\FatEnums\StateMachine\InvalidStateTransition;

it('defines the expected cases', function () {
    expect(DeploymentStatus::cases())->toHaveCount(7);
});

it('allows pending to starting', function () {
    expect(DeploymentStatus::Pending->canTransitionTo(DeploymentStatus::Starting))->toBeTrue();
});

it('allows starting to running or failed', function () {
    expect(DeploymentStatus::Starting->canTransitionTo(DeploymentStatus::Running))->toBeTrue();
    expect(DeploymentStatus::Starting->canTransitionTo(DeploymentStatus::Failed))->toBeTrue();
});

it('allows running to hibernated, destroying, or failed', function () {
    expect(DeploymentStatus::Running->canTransitionTo(DeploymentStatus::Hibernated))->toBeTrue();
    expect(DeploymentStatus::Running->canTransitionTo(DeploymentStatus::Destroying))->toBeTrue();
    expect(DeploymentStatus::Running->canTransitionTo(DeploymentStatus::Failed))->toBeTrue();
});

it('allows hibernated to starting or destroying', function () {
    expect(DeploymentStatus::Hibernated->canTransitionTo(DeploymentStatus::Starting))->toBeTrue();
    expect(DeploymentStatus::Hibernated->canTransitionTo(DeploymentStatus::Destroying))->toBeTrue();
});

it('allows destroying to destroyed', function () {
    expect(DeploymentStatus::Destroying->canTransitionTo(DeploymentStatus::Destroyed))->toBeTrue();
});

it('allows failed to recover via starting or destroying', function () {
    expect(DeploymentStatus::Failed->canTransitionTo(DeploymentStatus::Starting))->toBeTrue();
    expect(DeploymentStatus::Failed->canTransitionTo(DeploymentStatus::Destroying))->toBeTrue();
});

it('treats destroyed as terminal', function () {
    expect(DeploymentStatus::Destroyed->canTransitionTo(DeploymentStatus::Pending))->toBeFalse();
    expect(DeploymentStatus::Destroyed->canTransitionTo(DeploymentStatus::Starting))->toBeFalse();
});

it('throws on invalid transition', function () {
    DeploymentStatus::Pending->transitionTo(DeploymentStatus::Running);
})->throws(InvalidStateTransition::class);

it('performs a valid transition', function () {
    expect(DeploymentStatus::Pending->transitionTo(DeploymentStatus::Starting))
        ->toBe(DeploymentStatus::Starting);
});
