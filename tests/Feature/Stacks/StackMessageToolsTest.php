<?php

use App\DataTransferObjects\YakToolCall;
use App\Enums\StackThreadMessageKind;
use App\Models\ClaudeAccount;
use App\Models\HeldThreadEvent;
use App\Models\PendingSteeringMessage;
use App\Models\StackMessage;
use App\Models\StackThreadMessage;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\YakToolRouter;

function sendCall(array $input): YakToolCall
{
    return new YakToolCall('c1', 'send_message', $input);
}

it('delivers a sibling message inside the stack', function () {
    $parent = Thread::factory()->ownedByDevice()->create();
    $first = Thread::factory()->child($parent)->create(['name' => 'first']);
    $second = Thread::factory()->child($parent)->create(['name' => 'second', ...['owner_side' => 'device', 'owner_device_id' => $parent->owner_device_id]]);

    $result = app(YakToolRouter::class)->handle($first, sendCall(['to' => 'second', 'text' => 'Use the v2 API.']));

    expect($result->isError)->toBeFalse()
        ->and($result->content)->toBe('Sent.')
        ->and(StackThreadMessage::query()->sole()->kind)->toBe(StackThreadMessageKind::Message)
        ->and(StackMessage::query()->where('thread_id', $second->id)->exists())->toBeTrue();
});

it('rejects a message to a thread in another stack', function () {
    $mine = Thread::factory()->child(Thread::factory()->create())->create();
    $other = Thread::factory()->create(['name' => 'elsewhere']);

    $byId = app(YakToolRouter::class)->handle($mine, sendCall(['to' => (string) $other->id, 'text' => 'Hi']));
    $byName = app(YakToolRouter::class)->handle($mine, sendCall(['to' => 'elsewhere', 'text' => 'Hi']));

    expect($byId->isError)->toBeTrue()
        ->and($byName->isError)->toBeTrue()
        ->and(StackThreadMessage::query()->count())->toBe(0);
});

it('sends a child question to the parent immediately as a question message', function () {
    $parent = Thread::factory()->create(['claude_account_id' => ClaudeAccount::factory()->create()->id]);
    YakTask::factory()->create(['thread_id' => $parent->id]);
    $child = Thread::factory()->child($parent)->create();

    $result = app(YakToolRouter::class)->handle($child, sendCall(['to' => 'parent', 'text' => 'Which table?']));

    expect($result->isError)->toBeFalse()
        ->and(StackThreadMessage::query()->sole()->kind)->toBe(StackThreadMessageKind::Question)
        ->and(PendingSteeringMessage::query()->count() + $parent->runs()->count())->toBeGreaterThan(0);
});

it('holds a child question for the person when the parent is on a device', function () {
    $parent = Thread::factory()->ownedByDevice()->create();
    $child = Thread::factory()->child($parent)->create();

    $result = app(YakToolRouter::class)->handle($child, sendCall(['to' => 'parent', 'text' => 'Which table?']));

    expect($result->isError)->toBeFalse()
        ->and(HeldThreadEvent::query()->where('thread_id', $parent->id)->count())->toBe(1)
        ->and(StackMessage::query()->count())->toBe(0)
        ->and(StackThreadMessage::query()->count())->toBe(0);
});

it('lists the stack with states', function () {
    $parent = Thread::factory()->create(['name' => 'main', 'kind' => 'root']);
    Thread::factory()->child($parent)->create(['name' => 'docs', 'result_summary' => 'Found it.']);
    Thread::factory()->child($parent)->create(['name' => 'old', 'status' => 'closed']);

    $result = app(YakToolRouter::class)->handle($parent, new YakToolCall('c1', 'list_threads', []));

    expect($result->content)->toContain('- main (root, queued)')
        ->toContain('- docs (implement, done): Found it.')
        ->toContain('- old (implement, closed)');
});
