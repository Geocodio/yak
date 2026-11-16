<?php

use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadEventPublisher;
use App\Services\Threads\ThreadEventWindow;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Broadcast;

require_once __DIR__ . '/../Feature/Threads/Realtime/RealtimeRedis.php';

/*
 * Journey 3 needs a Reverb server the browser can reach, so the frontend bundle must be built with
 *   VITE_REVERB_APP_KEY=yak-e2e-key VITE_REVERB_HOST=127.0.0.1 VITE_REVERB_PORT=18080 npm run build
 */
const REALTIME_E2E_KEY = 'yak-e2e-key';
const REALTIME_E2E_PORT = 18080;

function startReverb(): mixed
{
    $process = proc_open(
        [PHP_BINARY, 'artisan', 'reverb:start', '--host=127.0.0.1', '--port=' . REALTIME_E2E_PORT],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        base_path(),
        array_merge(getenv(), [
            'REVERB_APP_ID' => 'yak-e2e',
            'REVERB_APP_KEY' => REALTIME_E2E_KEY,
            'REVERB_APP_SECRET' => 'yak-e2e-secret',
            'REVERB_HOST' => '127.0.0.1',
            'REVERB_PORT' => (string) REALTIME_E2E_PORT,
            'REVERB_SCHEME' => 'http',
        ]),
    );

    for ($attempt = 0; $attempt < 100; $attempt++) {
        if ($socket = @fsockopen('127.0.0.1', REALTIME_E2E_PORT)) {
            fclose($socket);

            return $process;
        }

        usleep(100_000);
    }

    proc_terminate($process);
    test()->fail('Reverb did not start.');
}

function publishAssistantText(ThreadEventPublisher $publisher, int $threadId, string $messageId): void
{
    $publisher->append($threadId, 'entry', ['role' => 'assistant', 'messageId' => $messageId, 'blocks' => [['type' => 'text', 'text' => "text of {$messageId}"]]]);
}

function threadEventStateScript(string $attribute): string
{
    return "document.querySelector('[data-testid=\"thread-event-state\"]').getAttribute('{$attribute}')";
}

it('shows live updates and catches up after a reconnect', function () {
    $bundle = implode('', array_map('file_get_contents', glob(public_path('build/assets/*.js')) ?: []));

    if (! str_contains($bundle, REALTIME_E2E_KEY)) {
        test()->markTestSkipped('The frontend bundle was not built with the realtime E2E variables (see the header of this file).');
    }

    useThrowawayRedis();
    $reverb = startReverb();

    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => REALTIME_E2E_KEY,
        'broadcasting.connections.reverb.secret' => 'yak-e2e-secret',
        'broadcasting.connections.reverb.app_id' => 'yak-e2e',
        'broadcasting.connections.reverb.options' => ['host' => '127.0.0.1', 'port' => REALTIME_E2E_PORT, 'scheme' => 'http', 'useTLS' => false],
    ]);
    app()->forgetInstance(BroadcastManager::class);
    Broadcast::purge();
    require base_path('routes/channels.php');

    try {
        $user = User::factory()->create();
        $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);
        $publisher = app(ThreadEventPublisher::class);
        $this->actingAs($user);

        foreach (['m1', 'm2', 'm3'] as $messageId) {
            publishAssistantText($publisher, $thread->id, $messageId);
        }

        $page = visit("/threads/{$thread->public_id}");
        $page->wait(2)->assertScript(threadEventStateScript('data-item-keys'), 'm1:0,m2:0,m3:0')
            ->assertScript(threadEventStateScript('data-last-sequence'), '3')
            ->assertScript(threadEventStateScript('data-status'), 'live');

        publishAssistantText($publisher, $thread->id, 'm4');
        $page->wait(1)->assertScript(threadEventStateScript('data-item-keys'), 'm1:0,m2:0,m3:0,m4:0');

        $page->script('window.Echo.connector.pusher.disconnect()');
        $page->wait(1)->assertScript(threadEventStateScript('data-status'), 'offline');

        foreach (['m5', 'm6', 'm7'] as $messageId) {
            publishAssistantText($publisher, $thread->id, $messageId);
        }

        $page->script('window.Echo.connector.pusher.connect()');
        $page->wait(3)
            ->assertScript(threadEventStateScript('data-last-sequence'), '7')
            ->assertScript(threadEventStateScript('data-item-keys'), 'm1:0,m2:0,m3:0,m4:0,m5:0,m6:0,m7:0')
            ->assertScript(threadEventStateScript('data-status'), 'live')
            ->assertNoJavaScriptErrors();
    } finally {
        proc_terminate($reverb);
        app(ThreadEventWindow::class)->forget($thread->id ?? 0);
        stopThrowawayRedis();
    }
});
