<?php

use App\Enums\StackThreadMessageKind;
use App\Enums\ThreadStatus;
use App\Exceptions\ThreadActionRefused;
use App\Jobs\DeliverStackNoteJob;
use App\Models\StackMessage;
use App\Models\StackThreadMessage;
use App\Models\Thread;
use App\Models\User;
use App\Services\Stacks\StackActions;
use App\Services\Stacks\StackMessenger;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadTurns;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BusyRow;

beforeEach(function () {
    $this->person = User::factory()->create(['name' => 'Test person']);
    $this->root = Thread::factory()->ownedByDevice()->create();
});

function failedChildOf(Thread $root, string $name = 'scout'): Thread
{
    $child = Thread::factory()->child($root)->create(['name' => $name]);
    StackThreadMessage::factory()->create([
        'root_thread_id' => $root->id,
        'from_thread_id' => $child->id,
        'to_thread_id' => $root->id,
        'kind' => StackThreadMessageKind::Failure,
        'delivered_at' => now(),
    ]);

    return $child;
}

function personNotes(): Collection
{
    return StackThreadMessage::query()->whereNotNull('from_user_id')->get();
}

it('splits once and sends one note from the person', function () {
    $child = failedChildOf($this->root);

    app(StackActions::class)->split($child, $this->person);
    app(StackActions::class)->split($child, $this->person);

    $note = personNotes()->sole();
    expect($note->from_user_id)->toBe($this->person->id)
        ->and($note->to_thread_id)->toBe($this->root->id)
        ->and($note->delivered_at)->not->toBeNull()
        ->and(StackMessage::query()->sole()->text)->toStartWith('[Note from Test person, the person driving this stack.]')
        ->and($child->fresh()->split_requested_at)->not->toBeNull();
});

it('drops once and sends one note', function () {
    $child = failedChildOf($this->root);

    app(StackActions::class)->drop($child, $this->person);
    app(StackActions::class)->drop($child, $this->person);

    $child->refresh();
    expect($child->status)->toBe(ThreadStatus::Closed)
        ->and($child->close_reason)->toBe('dropped')
        ->and(personNotes())->toHaveCount(1)
        ->and(StackMessage::query()->count())->toBe(1);
});

it('never wakes the parent inside the request', function (string $action) {
    Queue::fake();
    $sandbox = $this->mock(ThreadSandbox::class);
    $sandbox->shouldNotReceive('ensureAwake');
    $sandbox->shouldReceive('destroy');
    $this->mock(ThreadTurns::class)->shouldNotReceive('start');
    $root = Thread::factory()->create();
    $child = failedChildOf($root);

    app(StackActions::class)->{$action}($child, $this->person);

    $note = personNotes()->sole();
    expect($note->delivered_at)->toBeNull();
    Queue::assertPushed(DeliverStackNoteJob::class, 1);
    Queue::assertPushed(DeliverStackNoteJob::class, fn (DeliverStackNoteJob $job): bool => $job->messageId === $note->id && $job->afterCommit === true);
})->with(['split', 'drop']);

it('delivers a note once when its job runs twice', function () {
    $child = failedChildOf($this->root);
    Queue::fake();
    app(StackActions::class)->split($child, $this->person);
    $note = personNotes()->sole();

    $messenger = Mockery::spy(StackMessenger::class);
    $job = new DeliverStackNoteJob($note->id);
    $job->handle($messenger);
    $job->handle($messenger);

    $messenger->shouldHaveReceived('deliver')->once();
    expect($note->fresh()->delivered_at)->not->toBeNull();
});

it('delivers nothing for an agent message', function () {
    $child = failedChildOf($this->root);
    $agentNote = StackThreadMessage::factory()->create([
        'root_thread_id' => $this->root->id,
        'from_thread_id' => $child->id,
        'to_thread_id' => $this->root->id,
        'kind' => StackThreadMessageKind::Message,
    ]);
    $messenger = Mockery::spy(StackMessenger::class);

    (new DeliverStackNoteJob($agentNote->id))->handle($messenger);

    $messenger->shouldNotHaveReceived('deliver');
});

it('lets the note be delivered later when every try failed', function () {
    $child = failedChildOf($this->root);
    Queue::fake();
    app(StackActions::class)->split($child, $this->person);
    $note = personNotes()->sole();

    $messenger = Mockery::mock(StackMessenger::class);
    $messenger->shouldReceive('deliver')->andThrow(new RuntimeException('sandbox down'));
    $job = new DeliverStackNoteJob($note->id);

    expect(fn () => $job->handle($messenger))->toThrow(RuntimeException::class);
    expect($note->fresh()->delivered_at)->toBeNull();

    Log::spy();
    $job->failed(new RuntimeException('sandbox down'));
    Log::shouldHaveReceived('error')->once()->with('A stack note could not be delivered', ['message' => $note->id, 'error' => RuntimeException::class]);

    app(StackActions::class)->split($child, $this->person);
    Queue::assertPushed(DeliverStackNoteJob::class, 2);
    expect(personNotes())->toHaveCount(1);

    (new DeliverStackNoteJob($note->id))->handle(app(StackMessenger::class));
    app(StackActions::class)->split($child, $this->person);
    Queue::assertPushed(DeliverStackNoteJob::class, 2);
});

it('never throttles the person', function () {
    foreach (range(1, 7) as $number) {
        app(StackActions::class)->split(failedChildOf($this->root, "scout-{$number}"), $this->person);
    }

    expect(personNotes())->toHaveCount(7)
        ->and(Thread::query()->whereNotNull('messages_paused_at')->exists())->toBeFalse();
});

it('refuses a working child', function (string $action) {
    $child = Thread::factory()->child($this->root)->create();

    try {
        app(StackActions::class)->{$action}($child, $this->person);
        $this->fail('The action was not refused.');
    } catch (ThreadActionRefused $refusal) {
        expect($refusal->errorCode)->toBe('child_not_failed')
            ->and($refusal->getMessage())->toBe('Only a sub-thread that failed can be taken over, split or dropped.')
            ->and($refusal->details)->toHaveKey('state');
    }

    expect(personNotes())->toHaveCount(0)
        ->and($child->fresh()->isOpen())->toBeTrue()
        ->and($child->fresh()->taken_over_at)->toBeNull()
        ->and($child->fresh()->split_requested_at)->toBeNull();
})->with(['takeOver', 'split', 'drop']);

it('refuses split and drop after a take over', function (string $action) {
    $child = failedChildOf($this->root);
    app(StackActions::class)->takeOver($child, $this->person);

    try {
        app(StackActions::class)->{$action}($child, $this->person);
        $this->fail('The action was not refused.');
    } catch (ThreadActionRefused $refusal) {
        expect($refusal->errorCode)->toBe('child_not_failed')
            ->and($refusal->details)->toBe(['state' => 'failed', 'takenOver' => true]);
    }

    expect(personNotes())->toHaveCount(0);
})->with(['split', 'drop']);

it('refuses a closed child and a closed parent', function () {
    $closed = failedChildOf($this->root);
    $closed->forceFill(['status' => ThreadStatus::Closed, 'closed_at' => now(), 'close_reason' => 'manual'])->save();

    foreach (['takeOver', 'split'] as $action) {
        expect(fn () => app(StackActions::class)->{$action}($closed, $this->person))
            ->toThrow(fn (ThreadActionRefused $refusal) => expect($refusal->errorCode)->toBe('thread_closed')->and($refusal->getMessage())->toBe('This sub-thread is closed.'));
    }

    $child = failedChildOf($this->root, 'other');
    $this->root->forceFill(['status' => ThreadStatus::Closed, 'closed_at' => now(), 'close_reason' => 'manual'])->save();
    $child->refresh();

    expect(fn () => app(StackActions::class)->split($child, $this->person))
        ->toThrow(fn (ThreadActionRefused $refusal) => expect($refusal->getMessage())->toBe('The parent thread is closed, so it cannot split this work.'));
});

it('answers busy when the root lock wait runs out', function (string $action) {
    Queue::fake();
    $child = failedChildOf($this->root);
    BusyRow::on(Thread::class, $this->root);

    expect(fn () => app(StackActions::class)->{$action}($child, $this->person))
        ->toThrow(fn (HttpException $exception) => expect($exception->getStatusCode())->toBe(503));

    expect(personNotes())->toHaveCount(0)
        ->and($child->fresh()->taken_over_at)->toBeNull()
        ->and($child->fresh()->split_requested_at)->toBeNull()
        ->and($child->fresh()->isOpen())->toBeTrue();
    Queue::assertNothingPushed();
})->with(['takeOver', 'split', 'drop']);
