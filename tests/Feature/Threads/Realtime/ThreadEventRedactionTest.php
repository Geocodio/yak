<?php

use App\Events\ThreadActivity;
use App\Events\ThreadEventBroadcast;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Services\Threads\ThreadEventWindow;
use App\Support\SecretRedactor;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/RealtimeRedis.php';

beforeEach(function () {
    config(['yak.threads.realtime_key_prefix' => parallelToken() . ':']);
    useThrowawayRedis();
    Event::fake([ThreadEventBroadcast::class]);
});

afterEach(fn () => stopThrowawayRedis());

it('never carries a credential through a broadcast, the window or the table', function () {
    $anthropicKey = 'sk-ant-api03-abcdefghijklmnop';
    $placeholder = 'yakph-' . str_repeat('a1b2c3d4', 4);
    $thread = Thread::factory()->create();

    $redacted = SecretRedactor::redact("key {$anthropicKey} and {$placeholder}");
    event(new ThreadActivity($thread, 1, 1, [
        'type' => 'user',
        'uuid' => 'u1',
        'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => $redacted]]],
    ]));

    $stored = json_encode(ThreadEvent::query()->where('thread_id', $thread->id)->get()->toArray());
    $window = json_encode(app(ThreadEventWindow::class)->since($thread->id, 0, 100));
    $broadcast = json_encode(Event::dispatched(ThreadEventBroadcast::class)
        ->map(fn (array $arguments): array => $arguments[0]->broadcastWith())->all());

    expect($stored)->toContain('[redacted]');

    foreach ([$stored, $window, $broadcast] as $surface) {
        expect($surface)->not->toContain('sk-ant-')->not->toContain('yakph-');
    }
});
