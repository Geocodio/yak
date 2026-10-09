<?php

use App\Enums\SteeringMode;
use App\Enums\TaskStatus;
use App\Models\PendingSteeringMessage;
use App\Models\User;
use App\Models\YakTask;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('a message sent while Yak works waits in the banner above the text box', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);

    $page = visit(route('tasks.show', $task));

    $page->click('[data-testid="composer-mode"]')
        ->click('[data-testid="composer-mode-steer"]')
        ->assertSeeIn('[data-testid="composer-submit"]', 'Steer')
        ->fill('[data-testid="composer-input"]', 'use the v2 endpoint')
        ->click('[data-testid="composer-submit"]')
        ->assertSeeIn('[data-testid="queued-messages"]', 'use the v2 endpoint')
        ->assertSeeIn('[data-testid="queued-messages"]', 'Steering')
        ->assertValue('[data-testid="composer-input"]', '');

    expect(PendingSteeringMessage::sole()->mode)->toBe(SteeringMode::Steer);
});

test('a waiting message switches between queued and steered', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);
    $message = PendingSteeringMessage::queueFor($task, 'add docs afterwards', 'dashboard');

    $page = visit(route('tasks.show', $task));

    $page->click("[data-testid=\"queued-message-mode-{$message->id}\"]")
        ->assertAttribute("[data-testid=\"queued-message-mode-{$message->id}\"]", 'data-mode', 'steer');

    expect($message->fresh()->mode)->toBe(SteeringMode::Steer);
});

test('the X removes a waiting message', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);
    $message = PendingSteeringMessage::queueFor($task, 'never mind this', 'dashboard');

    $page = visit(route('tasks.show', $task));

    $page->click("[data-testid=\"queued-message-remove-{$message->id}\"]")
        ->assertMissing('[data-testid="queued-messages"]');

    expect(PendingSteeringMessage::count())->toBe(0);
});

test('editing a waiting message takes it off the queue and into the text box', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);
    $message = PendingSteeringMessage::queueFor($task, 'rename the column', 'dashboard', mode: SteeringMode::Steer);

    $page = visit(route('tasks.show', $task));

    $page->click("[data-testid=\"queued-message-edit-{$message->id}\"]")
        ->assertMissing('[data-testid="queued-messages"]')
        ->assertValue('[data-testid="composer-input"]', 'rename the column')
        ->assertSeeIn('[data-testid="composer-submit"]', 'Steer');

    expect(PendingSteeringMessage::count())->toBe(0);
});

test('up arrow in an empty text box edits the latest waiting message', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);
    PendingSteeringMessage::queueFor($task, 'older note', 'dashboard');
    PendingSteeringMessage::queueFor($task, 'latest note', 'dashboard');

    $page = visit(route('tasks.show', $task));

    $page->click('[data-testid="composer-input"]')
        ->keys('[data-testid="composer-input"]', 'ArrowUp')
        ->assertValue('[data-testid="composer-input"]', 'latest note')
        ->assertSeeIn('[data-testid="queued-messages"]', 'older note');

    expect(PendingSteeringMessage::pluck('text')->all())->toBe(['older note']);
});
