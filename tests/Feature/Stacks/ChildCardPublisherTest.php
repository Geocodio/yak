<?php

use App\Enums\StackThreadMessageKind;
use App\Enums\ThreadKind;
use App\Events\ThreadActivity;
use App\Models\StackThreadMessage;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Services\Stacks\ChildCardPublisher;
use App\Services\Stacks\ChildSpawner;
use Illuminate\Support\Facades\DB;

function childEvents(Thread $parent): array
{
    return DB::table('thread_events')->where('thread_id', $parent->id)->where('type', 'child')->orderBy('sequence')->get()
        ->map(fn (object $row): array => json_decode($row->payload, true))->all();
}

function childToolUseLine(string $tool): array
{
    return ['type' => 'assistant', 'message' => ['id' => 'm1', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => $tool, 'input' => []]]]];
}

beforeEach(function () {
    $this->parent = Thread::factory()->create();
    $this->child = Thread::factory()->child($this->parent)->create(['name' => 'scout', 'kind' => ThreadKind::Research, 'model' => 'haiku', 'branch' => null]);
});

it('appends one stored child event to the parent stream', function () {
    app(ChildCardPublisher::class)->publish($this->child);

    $events = childEvents($this->parent);

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBe([
            'childThreadId' => $this->child->id,
            'name' => 'scout',
            'kind' => 'research',
            'model' => 'haiku',
            'state' => 'queued',
            'step' => null,
            'resultSummary' => null,
            'branch' => null,
            'messagesPaused' => false,
            'mergedAt' => null,
            'question' => null,
            'filesChanged' => null,
            'additions' => null,
            'deletions' => null,
            'conflictResolved' => false,
            'decision' => null,
        ]);
    expect(DB::table('thread_events')->where('thread_id', $this->child->id)->where('type', 'child')->count())->toBe(0);
});

it('does nothing for a thread without a parent', function () {
    app(ChildCardPublisher::class)->publish($this->parent);

    expect(childEvents($this->parent))->toBe([]);
});

it('records the tool name as the step of a child and throttles the card', function () {
    event(new ThreadActivity($this->child, 1, 1, childToolUseLine('Grep')));
    event(new ThreadActivity($this->child, 1, 2, childToolUseLine('Read')));

    expect($this->child->fresh()->current_step)->toBe('Read')
        ->and(childEvents($this->parent))->toHaveCount(1)
        ->and(childEvents($this->parent)[0]['step'])->toBe('Grep');
});

it('ignores lines that are not tool calls and threads that are not children', function () {
    event(new ThreadActivity($this->child, 1, 1, ['type' => 'assistant', 'message' => ['id' => 'm', 'content' => [['type' => 'text', 'text' => 'hi']]]]));
    event(new ThreadActivity($this->parent, 1, 1, childToolUseLine('Grep')));

    expect($this->child->fresh()->current_step)->toBeNull()
        ->and($this->parent->fresh()->current_step)->toBeNull()
        ->and(childEvents($this->parent))->toBe([]);
});

it('publishes a card when a child is spawned and when it ends', function () {
    $root = Thread::factory()->create();
    $spawned = app(ChildSpawner::class)->spawn($root, 'worker', 'Do it.', ThreadKind::Research, null);

    expect(childEvents($root))->toHaveCount(1)
        ->and(childEvents($root)[0]['childThreadId'])->toBe($spawned->id)
        ->and(childEvents($root)[0]['state'])->toBe('queued');
});

it('publishes the open question of a waiting child and the file totals of its diff', function () {
    ThreadDiff::factory()->create(['thread_id' => $this->child->id, 'files_count' => 3, 'additions' => 12, 'deletions' => 4]);
    StackThreadMessage::factory()->create(['root_thread_id' => $this->parent->id, 'from_thread_id' => $this->child->id, 'to_thread_id' => $this->parent->id, 'kind' => StackThreadMessageKind::Question, 'text' => 'Which table?']);

    app(ChildCardPublisher::class)->publish($this->child);

    expect(childEvents($this->parent)[0])->toMatchArray(['question' => 'Which table?', 'filesChanged' => 3, 'additions' => 12, 'deletions' => 4]);
});

it('drops the question once the parent has answered it', function () {
    StackThreadMessage::factory()->create(['root_thread_id' => $this->parent->id, 'from_thread_id' => $this->child->id, 'to_thread_id' => $this->parent->id, 'kind' => StackThreadMessageKind::Question, 'text' => 'Which table?', 'created_at' => now()->subMinute()]);
    StackThreadMessage::factory()->create(['root_thread_id' => $this->parent->id, 'from_thread_id' => $this->parent->id, 'to_thread_id' => $this->child->id, 'kind' => StackThreadMessageKind::Answer, 'text' => 'users']);

    app(ChildCardPublisher::class)->publish($this->child);

    expect(childEvents($this->parent)[0]['question'])->toBeNull();
});
