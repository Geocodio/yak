<?php

use App\Enums\TurnState;
use App\Events\ThreadActivity;
use App\Listeners\PublishThreadActivity;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\E2e\E2eRunnerScript;
use App\Services\Threads\ThreadEventPublisher;
use App\Services\Threads\ThreadEventWindow;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Broadcast;

require_once __DIR__ . '/../Feature/Threads/Realtime/RealtimeRedis.php';

/*
 * A streamed reply needs a Reverb server the browser can reach, so the frontend bundle must be built with
 *   VITE_REVERB_APP_KEY=yak-e2e-key VITE_REVERB_HOST=127.0.0.1 VITE_REVERB_PORT=18080 npm run build
 * The fake runner's streamed-reply script is fed through the same listener the thread driver uses, in recorded time
 * stretched by STREAM_PACE.
 */
const STREAMING_E2E_KEY = 'yak-e2e-key';
const STREAMING_E2E_PORT = 18080;
const STREAM_PACE = 1.5;
const MESSAGE = '[data-testid="thread-claude-message"]';
const SCROLLER = '[data-testid="thread-conversation-scroller"]';

/**
 * Reverb on the E2E port: the one CI started, or a new one that the caller stops.
 *
 * @return resource|null
 */
function streamingReverb(): mixed
{
    if ($socket = @fsockopen('127.0.0.1', STREAMING_E2E_PORT)) {
        fclose($socket);

        return null;
    }

    $process = proc_open(
        [PHP_BINARY, 'artisan', 'reverb:start', '--host=127.0.0.1', '--port=' . STREAMING_E2E_PORT],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        base_path(),
        array_merge(getenv(), [
            'CACHE_STORE' => 'array',
            'REVERB_APP_ID' => 'yak-e2e',
            'REVERB_APP_KEY' => STREAMING_E2E_KEY,
            'REVERB_APP_SECRET' => 'yak-e2e-secret',
            'REVERB_HOST' => '127.0.0.1',
            'REVERB_PORT' => (string) STREAMING_E2E_PORT,
            'REVERB_SCHEME' => 'http',
        ]),
    );

    for ($attempt = 0; $attempt < 100; $attempt++) {
        if ($socket = @fsockopen('127.0.0.1', STREAMING_E2E_PORT)) {
            fclose($socket);

            return $process;
        }

        usleep(100_000);
    }

    proc_terminate($process);
    test()->fail('Reverb did not start.');
}

/**
 * Plays the streamed-reply turn into a thread, line by line in the script's own time.
 */
final class StreamedTurnPlayback
{
    /** @var list<array{afterMilliseconds: int, line: array<string, mixed>, fromBroker: bool}> */
    private array $lines;

    private int $next = 0;

    private ?float $startedAt = null;

    public function __construct(private readonly Thread $thread, string $marker = E2eRunnerScript::STREAM_MARKER)
    {
        $script = new E2eRunnerScript(pace: STREAM_PACE);
        $script->respond("threads:{$thread->id}", ['type' => 'start_runner', 'container' => 'thread', 'start' => ['type' => 'start']], 1);
        $this->lines = $script->respond("threads:{$thread->id}", ['type' => 'turn', 'uuid' => '11111111-2222-4333-8444-555555555555', 'text' => 'Explain it ' . $marker], 1);
    }

    public function isFinished(): bool
    {
        return $this->next >= count($this->lines);
    }

    /**
     * Plays the lines due within `$milliseconds` of the first call, or all of them when null.
     */
    public function playUntil(?int $milliseconds = null): void
    {
        $this->startedAt ??= microtime(true);
        $listener = app(PublishThreadActivity::class);

        while (! $this->isFinished()) {
            $scripted = $this->lines[$this->next];

            if ($milliseconds !== null && $scripted['afterMilliseconds'] > $milliseconds) {
                return;
            }

            $wait = $this->startedAt + $scripted['afterMilliseconds'] / 1000 - microtime(true);

            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }

            $listener->handle(new ThreadActivity($this->thread, 1, $this->next + 1, $scripted['line']));
            $this->next++;
        }
    }

    /** The recorded time at which the first text block is final, in script milliseconds. */
    public function firstTextFinalAt(): int
    {
        foreach ($this->lines as $scripted) {
            if (($scripted['line']['type'] ?? null) === 'assistant' && ($scripted['line']['message']['content'][0]['type'] ?? null) === 'text') {
                return $scripted['afterMilliseconds'];
            }
        }

        throw new RuntimeException('The streamed-reply script has no assistant text line.');
    }
}

/**
 * Records one sample per animation frame for every Claude message: its box, the scroller's position and what the
 * message shows. Elements are numbered in the order they are first seen, so a message keeps its number.
 */
function installFrameSampler(mixed $page): void
{
    $page->script(<<<'JS'
        (() => {
            window.__samples = [];
            const ids = new Map();
            const loop = () => {
                const scroller = document.querySelector('[data-testid="thread-conversation-scroller"]');
                document.querySelectorAll('[data-testid="thread-claude-message"]').forEach((message) => {
                    if (!ids.has(message)) { ids.set(message, ids.size); }
                    const box = message.getBoundingClientRect();
                    window.__samples.push({
                        id: ids.get(message),
                        busy: message.getAttribute('aria-busy') === 'true',
                        top: box.top,
                        height: box.height,
                        layoutTop: (() => { let offset = 0; for (let node = message; node; node = node.offsetParent) { offset += node.offsetTop; } return offset; })(),
                        isAnimating: message.getAnimations().some((animation) => animation.playState === 'running'),
                        scrollTop: scroller ? scroller.scrollTop : null,
                        text: message.innerText,
                        startsWhole: /^Scrubbed /.test(message.innerText.split('\n').slice(2).join('\n').replace(/^[^\n]*Sonnet[^\n]*\n/, '').trimStart()),
                        hasHeading: !!message.querySelector('h2'),
                        hasList: !!message.querySelector('ul, ol'),
                        hasCode: !!message.querySelector('pre'),
                        hasTable: !!message.querySelector('table'),
                        hasMarkdownBold: message.innerText.includes('**'),
                        hasMarker: !!message.querySelector('[data-testid="thread-cut-marker"]'),
                        hasTableRow: /^\s*\|/m.test(message.innerText),
                    });
                });
                requestAnimationFrame(loop);
            };
            requestAnimationFrame(loop);
        })()
        JS);
}

/**
 * @return list<array<string, mixed>>
 */
function frameSamples(mixed $page): array
{
    return json_decode((string) $page->script('JSON.stringify(window.__samples)'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * A thread with enough history that its conversation scrolls, opened in a page with the frame sampler running.
 *
 * @return array{0: mixed, 1: Thread, 2: StreamedTurnPlayback}
 */
function openStreamingThread(string $marker = E2eRunnerScript::STREAM_MARKER): array
{
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);
    $publisher = app(ThreadEventPublisher::class);
    TaskRun::factory()->create(['yak_task_id' => YakTask::factory()->create(['thread_id' => $thread->id])->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::Running]);

    foreach (range(1, 14) as $number) {
        $publisher->append($thread->id, 'entry', ['role' => 'assistant', 'messageId' => "history-{$number}", 'blocks' => [['type' => 'text', 'text' => "History message {$number}\n\nA few words so the row has some height."]]]);
    }

    test()->actingAs($user);
    $page = visit("/threads/{$thread->public_id}")->resize(1280, 800);
    $page->wait(2)->assertScript(threadStreamingState('data-status'), 'live');
    installFrameSampler($page);

    return [$page, $thread, new StreamedTurnPlayback($thread, $marker)];
}

/**
 * Waits, up to 10 seconds, until no message is streaming, then one more frame so the final layout is sampled.
 */
function waitUntilNotStreaming(mixed $page): void
{
    $isDone = $page->script('(async () => { for (let attempt = 0; attempt < 200 && document.querySelector(\'' . MESSAGE . '[aria-busy="true"]\'); attempt++) { await new Promise((resolve) => setTimeout(resolve, 50)); } await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))); return !document.querySelector(\'' . MESSAGE . '[aria-busy="true"]\'); })()');

    expect($isDone)->toBeTrue('A message was still streaming after 10 seconds.');
}

function threadStreamingState(string $attribute): string
{
    return "document.querySelector('[data-testid=\"thread-event-state\"]').getAttribute('{$attribute}')";
}

/**
 * Runs the body with Reverb, a throwaway Redis and the broadcaster pointed at them.
 */
function withStreamingServers(Closure $body): void
{
    $bundle = implode('', array_map('file_get_contents', glob(public_path('build/assets/*.js')) ?: []));

    if (! str_contains($bundle, STREAMING_E2E_KEY)) {
        test()->markTestSkipped('The frontend bundle was not built with the realtime E2E variables (see the header of this file).');
    }

    useThrowawayRedis();
    $reverb = streamingReverb();

    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => STREAMING_E2E_KEY,
        'broadcasting.connections.reverb.secret' => 'yak-e2e-secret',
        'broadcasting.connections.reverb.app_id' => 'yak-e2e',
        'broadcasting.connections.reverb.options' => ['host' => '127.0.0.1', 'port' => STREAMING_E2E_PORT, 'scheme' => 'http', 'useTLS' => false],
    ]);
    app()->forgetInstance(BroadcastManager::class);
    Broadcast::purge();
    require base_path('routes/channels.php');

    try {
        $body();
    } finally {
        if ($reverb !== null) {
            proc_terminate($reverb);
        }

        app(ThreadEventWindow::class)->forget(Thread::query()->max('id') ?? 0);
        stopThrowawayRedis();
    }
}

it('does not move the reply when it finishes', function () {
    withStreamingServers(function () {
        [$page, , $playback] = openStreamingThread();
        $playback->playUntil();
        waitUntilNotStreaming($page);

        $samples = frameSamples($page);
        $transitions = 0;
        $formattedReplyFinished = false;
        $lastByMessage = [];

        foreach ($samples as $sample) {
            $previous = $lastByMessage[$sample['id']] ?? null;
            $lastByMessage[$sample['id']] = $sample;

            if ($previous === null || ! $previous['busy'] || $sample['busy']) {
                continue;
            }

            $transitions++;
            $formattedReplyFinished = $formattedReplyFinished || $sample['hasHeading'];
            // The last delta and the final entry can land in one frame, so the final text may extend the last streamed text but never replace it.
            // A row's own slide-in (row-enter) moves its painted box, never its layout, so while it plays only the layout position is compared.
            if (! $sample['isAnimating'] && ! $previous['isAnimating']) {
                expect($sample['top'])->toEqualWithDelta($previous['top'], 0.5, "message {$sample['id']} moved when it finished");
            }

            expect($sample['layoutTop'])->toEqualWithDelta($previous['layoutTop'], 0.5, "message {$sample['id']} shifted in the layout when it finished")
                ->and($sample['height'])->toEqualWithDelta($previous['height'], 0.5, "message {$sample['id']} changed height when it finished")
                ->and($sample['scrollTop'])->toEqualWithDelta($previous['scrollTop'], 0.5, "the scroller jumped when message {$sample['id']} finished")
                ->and(array_slice(explode("\n", $sample['text']), 0, 2))->toBe(array_slice(explode("\n", $previous['text']), 0, 2), "message {$sample['id']} changed its header when it finished")
                ->and(str_starts_with($sample['text'], rtrim($previous['text'])))->toBeTrue("message {$sample['id']} showed other text when it finished");
        }

        expect($transitions)->toBeGreaterThanOrEqual(1)->and($formattedReplyFinished)->toBeTrue('the formatted reply never went from streaming to final');
        $page->assertNoJavaScriptErrors();
    });
});

it('shows streamed text formatted while it arrives', function () {
    withStreamingServers(function () {
        [$page, , $playback] = openStreamingThread();
        $playback->playUntil();
        waitUntilNotStreaming($page);

        $busy = array_values(array_filter(frameSamples($page), fn (array $sample): bool => $sample['busy']));

        expect($busy)->not->toBeEmpty()
            ->and(collect($busy)->contains(fn (array $sample): bool => $sample['hasHeading'] && $sample['hasList'] && $sample['hasCode'] && $sample['hasTable']))->toBeTrue('no streaming frame showed the heading, list, code block and table together')
            ->and(collect($busy)->contains(fn (array $sample): bool => $sample['hasMarkdownBold']))->toBeFalse('a streaming frame showed a literal **')
            ->and(collect($busy)->contains(fn (array $sample): bool => $sample['hasTableRow']))->toBeFalse('a streaming frame showed a table row as plain text');
    });
});

it('leaves a reader who scrolled up where they are', function () {
    withStreamingServers(function () {
        [$page, , $playback] = openStreamingThread();
        $playback->playUntil(2000);
        $page->wait(1);

        $page->script('document.querySelector(\'' . SCROLLER . '\').scrollTop = 150');
        $page->wait(0.5);
        $before = $page->script('document.querySelector(\'' . SCROLLER . '\').scrollTop');
        expect($before)->toBeGreaterThan(0);

        $playback->playUntil();
        waitUntilNotStreaming($page);

        $positions = array_column(array_filter(frameSamples($page), fn (array $sample): bool => $sample['scrollTop'] !== null), 'scrollTop');
        $firstAtStart = array_search(true, array_map(fn ($position): bool => abs($position - $before) <= 0.5, $positions), true);

        expect($firstAtStart)->not->toBeFalse();

        $moved = array_values(array_filter(array_slice($positions, $firstAtStart), fn ($position): bool => abs($position - $before) > 0.5));

        expect($moved)->toBe([], 'the scroller moved under a reader who scrolled up');
    });
});

it('shows a viewer who opens the thread mid-reply the block whole', function () {
    withStreamingServers(function () {
        [$page, $thread, $playback] = openStreamingThread();
        $playback->playUntil(5000);

        $second = visit("/threads/{$thread->public_id}")->resize(1280, 800);
        $second->wait(2)->assertScript(threadStreamingState('data-status'), 'live');
        installFrameSampler($second);

        $playback->playUntil();
        waitUntilNotStreaming($second);

        $streaming = array_values(array_filter(frameSamples($second), fn (array $sample): bool => $sample['busy']));
        $text = $second->script('JSON.stringify([...document.querySelectorAll(\'' . MESSAGE . '\')].map((message) => message.innerText))');
        $final = implode("\n", json_decode((string) $text, true, flags: JSON_THROW_ON_ERROR));

        expect($final)->toContain('Scrubbed heading')->toContain('See the scrubbed link for more.')->toContain('Scrubbed second paragraph summarising the result.')
            ->and($streaming)->not->toBeEmpty()
            ->and(collect($streaming)->every(fn (array $sample): bool => $sample['startsWhole']))->toBeTrue('the viewer saw a block without its start');
        $second->assertNoJavaScriptErrors();
    });
});

it('a stopped reply keeps its text and shows the marker', function () {
    withStreamingServers(function () {
        [$page, , $playback] = openStreamingThread(E2eRunnerScript::STREAM_STOPPED_MARKER);
        $playback->playUntil();
        waitUntilNotStreaming($page);
        $page->wait(1);

        $samples = frameSamples($page);
        $firstWithMarker = collect($samples)->first(fn (array $sample): bool => $sample['hasMarker']);
        $lastWithoutMarker = $firstWithMarker === null ? null : collect($samples)->last(fn (array $sample): bool => $sample['id'] === $firstWithMarker['id'] && ! $sample['hasMarker']);

        $page->assertSee('You stopped this reply');
        expect($firstWithMarker)->not->toBeNull()->and($lastWithoutMarker)->not->toBeNull()
            ->and($firstWithMarker['id'])->toBe($lastWithoutMarker['id'])
            ->and($firstWithMarker['top'])->toEqualWithDelta($lastWithoutMarker['top'], 0.5, 'the reply moved when the marker appeared')
            ->and($firstWithMarker['layoutTop'])->toEqualWithDelta($lastWithoutMarker['layoutTop'], 0.5)
            ->and(str_starts_with($firstWithMarker['text'], rtrim($lastWithoutMarker['text'])))->toBeTrue('the reply text changed when the marker appeared');
        $page->assertNoJavaScriptErrors();
    });
});
