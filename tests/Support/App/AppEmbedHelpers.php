<?php

use App\Models\Device;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Queue;

const EMBED_USER_AGENT = 'Mozilla/5.0 YakApp/1.0 (embed)';

/**
 * The session data of a signed-in embed web view, for withSession().
 *
 * @return array<string, mixed>
 */
function embedSessionData(User $user, Device $device, ?int $expiresAt = null): array
{
    return [
        app('auth')->guard('app-embed')->getName() => $user->id,
        'app_embed' => ['device_id' => $device->id, 'expires_at' => $expiresAt ?? now()->addHours(12)->getTimestamp()],
    ];
}

/**
 * A paired phone and the user it belongs to.
 *
 * @return array{0: User, 1: Device, 2: array<string, string>}
 */
function embedPhone(): array
{
    [$device, $headers] = phoneWithHeaders(['user_id' => User::factory()->create()->id]);

    return [$device->user, $device, $headers];
}

/**
 * Starts the next request with a signed-in embed session; guards cache users between requests of one test.
 *
 * @param  array<string, mixed>  $session
 */
function actingAsEmbed(array $session): void
{
    app('auth')->forgetGuards();
    test()->withSession($session)->withHeader('User-Agent', EMBED_USER_AGENT);
}

/**
 * Mints a code for the phone and returns it.
 *
 * @param  array<string, string>  $headers
 */
function mintEmbedCode(array $headers, string $path = '/app/embed/threads/d0e515ef'): string
{
    return (string) test()->postJson('/api/app/v1/web-session', ['path' => $path], $headers)->assertCreated()->json('code');
}

/** A log file the yak channel writes to for this test. */
function captureYakLog(): string
{
    $file = tempnam(sys_get_temp_dir(), 'yaklog');
    config(['logging.channels.yak.path' => $file]);
    app('log')->forgetChannel('yak');

    return $file;
}

/**
 * Opens a thread the way the app does: a guest page in a `YakApp/` web view trades a hand-off code, minted with the
 * phone's device token on the browser's own host, for the embed session and lands on the embed thread page.
 *
 * @param  array<string, string>  $headers
 */
function visitEmbedThread(Thread $thread, array $headers, int $width = 390, int $height = 844, bool $isDark = false): mixed
{
    $pending = visit('/login')->withUserAgent(EMBED_USER_AGENT);
    $page = ($isDark ? $pending->inDarkMode() : $pending->inLightMode())->resize($width, $height);
    $path = "/app/embed/threads/{$thread->public_id}";
    $encodedHeaders = json_encode($headers + ['Accept' => 'application/json', 'Content-Type' => 'application/json'], JSON_THROW_ON_ERROR);

    $page->script("(() => { setTimeout(async () => {
        const response = await fetch('/api/app/v1/web-session', { method: 'POST', headers: {$encodedHeaders}, body: JSON.stringify({ path: '{$path}' }) });
        const { code } = await response.json();
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/app/embed/session';
        form.innerHTML = '<input name=\"code\">';
        form.firstChild.value = code;
        document.body.appendChild(form);
        form.submit();
    }, 0); })()");

    return $page->assertPresent('[data-testid="embed-shell"]');
}

/**
 * Records the route name (or the path, for an unnamed route) and status of every request the app handles from now on.
 *
 * @return Closure(): list<array{route: string, status: int}>
 */
function recordEmbedRequests(): Closure
{
    $requests = [];
    app('events')->listen(RequestHandled::class, function (RequestHandled $event) use (&$requests): void {
        $requests[] = ['route' => $event->request->route()?->getName() ?? $event->request->path(), 'status' => $event->response->getStatusCode()];
    });

    return function () use (&$requests): array {
        return $requests;
    };
}

/**
 * A thread owned by the phone's user with an open question, as the embed page shows it.
 *
 * @return array{0: Thread, 1: array<string, string>, 2: User}
 */
function embedQuestionThread(): array
{
    Queue::fake();
    [$user, , $headers] = embedPhone();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id, 'title' => 'Add a retry to the import job']);
    YakTask::factory()->withClarificationQuestions([
        ['id' => 'scope', 'header' => 'Scope', 'question' => 'Which scope?', 'options' => [['label' => 'Small', 'description' => 'Only the import job'], ['label' => 'Large', 'description' => 'Every job']], 'multi_select' => false],
    ])->create(['thread_id' => $thread->id, 'started_by_user_id' => $user->id]);

    return [$thread, $headers, $user];
}
