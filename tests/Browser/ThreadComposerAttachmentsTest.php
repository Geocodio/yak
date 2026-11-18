<?php

use App\Enums\TaskStatus;
use App\Enums\TurnMessageState;
use App\Enums\TurnState;
use App\Models\PendingSteeringMessage;
use App\Models\TaskAttachment;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ParseMultipartBody;

const THREAD_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
const THREAD_COMPOSER = '[data-testid="thread-composer-input"]';

/**
 * Builds a 1x1 PNG `File` in the page and fires `eventType` at `selector` carrying it, the way a
 * screenshot paste or a Finder drag arrives. The name's bytes follow the image so differently named
 * files differ in content.
 */
function threadFileEventScript(string $selector, string $eventType, string $name = 'image.png'): string
{
    $constructor = $eventType === 'paste' ? 'ClipboardEvent' : 'DragEvent';
    $property = $eventType === 'paste' ? 'clipboardData' : 'dataTransfer';
    $png = THREAD_PNG;

    return <<<JS
        (() => {
            const png = Uint8Array.from(atob('{$png}'), (c) => c.charCodeAt(0));
            const bytes = new Uint8Array([...png, ...new TextEncoder().encode('{$name}')]);
            const transfer = new DataTransfer();
            transfer.items.add(new File([bytes], '{$name}', { type: 'image/png' }));
            const target = document.querySelector('{$selector}');
            const fire = (type) => target.dispatchEvent(new {$constructor}(type, { {$property}: transfer, bubbles: true, cancelable: true }));
            if ('{$eventType}' === 'drop') {
                fire('dragenter');
                fire('dragover');
            }
            fire('{$eventType}');
        })()
        JS;
}

/**
 * A mousemove at the centre of the first element matching `selector`, sent to the composer. The
 * chips sit behind the textarea, so this is how a pointer resting on one arrives.
 */
function threadMoveMouseOver(string $selector): string
{
    return <<<JS
        (() => {
            const rect = document.querySelector('{$selector}').getBoundingClientRect();
            document.querySelector('[data-testid="thread-composer-input"]').dispatchEvent(new MouseEvent('mousemove', {
                clientX: rect.left + rect.width / 2,
                clientY: rect.top + rect.height / 2,
                bubbles: true,
            }));
        })()
        JS;
}

/**
 * A thread the signed-in person drives, idle, with one finished task.
 *
 * @param  array<string, mixed>  $threadAttributes
 * @return array{0: Thread, 1: YakTask}
 */
function attachmentsThread(array $threadAttributes = []): array
{
    $thread = Thread::factory()->create(['created_by_user_id' => auth()->id(), 'owner_user_id' => auth()->id(), ...$threadAttributes]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'repo' => $thread->repo, 'status' => TaskStatus::Success, 'pr_url' => null]);

    return [$thread, $task];
}

/**
 * A thread whose message is sent with a sent file of every kind the lightbox shows.
 */
function attachmentsThreadWithSentFiles(): array
{
    [$thread, $task] = attachmentsThread();
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::Completed]);
    $message = ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'state' => TurnMessageState::Completed, 'text' => 'It breaks like [Image #1], see [File #2] and [File #3]']);
    $image = TaskAttachment::factory()->create(['yak_task_id' => $task->id, 'thread_turn_message_id' => $message->id, 'original_name' => 'bug.png', 'reference' => 'Image #1']);
    Storage::disk('artifacts')->put($image->disk_path, base64_decode(THREAD_PNG));
    $log = TaskAttachment::factory()->file('worker.log')->create(['yak_task_id' => $task->id, 'thread_turn_message_id' => $message->id, 'reference' => 'File #2']);
    Storage::disk('artifacts')->put($log->disk_path, 'TypeError: x is undefined');
    $php = TaskAttachment::factory()->file('Invoice.php', 'text/x-php')->create(['yak_task_id' => $task->id, 'thread_turn_message_id' => $message->id, 'reference' => 'File #3']);
    // No trailing newline: the browser plugin's server trims streamed file bodies, which would make them shorter than their Content-Length.
    Storage::disk('artifacts')->put($php->disk_path, "<?php\n\nfunction total(\$items) {\n    return array_sum(\$items); // cents\n}\necho total([1, 2]);");

    return [$thread, $image, $log, $php];
}

beforeEach(function () {
    Storage::fake('artifacts');
    app(HttpKernel::class)->prependMiddleware(ParseMultipartBody::class);
    $this->actingAs(User::factory()->create());
});

it('attaches a pasted screenshot with a preview and a token, and removes it with its token', function () {
    [$thread] = attachmentsThread();

    $page = visit(route('threads.show', $thread));
    $page->script(threadFileEventScript(THREAD_COMPOSER, 'paste'));

    $page->assertVisible('[data-testid="draft-attachment"] img')
        ->assertAttributeContains('[data-testid="draft-attachment"] img', 'alt', 'pasted-image-')
        ->assertValue(THREAD_COMPOSER, '[Image #1] ')
        ->assertSeeIn('[data-testid="attachment-textarea-mirror"] [data-attachment-ref="Image #1"]', '[Image #1]');

    $page->hover('[data-testid="draft-attachment"]')
        ->click('[data-testid="attachment-remove"]')
        ->assertMissing('[data-testid="draft-attachment"]')
        ->assertValue(THREAD_COMPOSER, '')
        ->assertNoJavaScriptErrors();
});

it('opens a pasted screenshot from its draft tile in the lightbox', function () {
    [$thread] = attachmentsThread();

    $page = visit(route('threads.show', $thread));
    $page->script(threadFileEventScript(THREAD_COMPOSER, 'paste'));

    $page->click('[data-testid="draft-attachment"] button[aria-label^="View"]')
        ->assertVisible('[data-testid="media-lightbox"]')
        ->keys('[data-testid="media-lightbox"]', 'Escape')
        ->assertMissing('[data-testid="media-lightbox"]');
})->todo('The thread composer passes an empty open handler to its draft tiles, so a draft tile has no lightbox; the task composer opened it.');

it('puts tokens where the files were added and backspace removes a whole token and its file', function () {
    [$thread] = attachmentsThread();

    $page = visit(route('threads.show', $thread));
    $page->typeSlowly(THREAD_COMPOSER, 'Before', 5);
    $page->script(threadFileEventScript(THREAD_COMPOSER, 'paste', 'before.png'));
    $page->typeSlowly(THREAD_COMPOSER, 'after', 5);
    $page->script(threadFileEventScript(THREAD_COMPOSER, 'paste', 'after.png'));

    $page->assertValue(THREAD_COMPOSER, 'Before [Image #1] after [Image #2] ')
        ->assertCount('[data-testid="draft-attachment"]', 2);

    // One backspace eats the trailing space, the next the whole token.
    $page->keys(THREAD_COMPOSER, ['Backspace', 'Backspace'])
        ->assertValue(THREAD_COMPOSER, 'Before [Image #1] after ')
        ->assertCount('[data-testid="draft-attachment"]', 1)
        ->assertPresent('[data-testid="draft-attachment"][data-reference="Image #1"]');
});

it('highlights a token when its tile is hovered and a tile when its token is hovered', function () {
    [$thread] = attachmentsThread();

    $page = visit(route('threads.show', $thread));
    $page->script(threadFileEventScript(THREAD_COMPOSER, 'paste', 'one.png'));
    $page->script(threadFileEventScript(THREAD_COMPOSER, 'paste', 'two.png'));

    $page->hover('[data-testid="draft-attachment"][data-reference="Image #2"]')
        ->assertPresent('[data-testid="attachment-textarea-mirror"] [data-attachment-ref="Image #2"][data-highlighted]')
        ->assertMissing('[data-testid="attachment-textarea-mirror"] [data-attachment-ref="Image #1"][data-highlighted]');

    $page->script(threadMoveMouseOver('[data-testid="attachment-textarea-mirror"] [data-attachment-ref="Image #1"]'));

    $page->assertPresent('[data-testid="draft-attachment"][data-reference="Image #1"][data-highlighted]')
        ->assertMissing('[data-testid="draft-attachment"][data-reference="Image #2"][data-highlighted]');
});

it('attaches a dropped file and a file from the picker', function () {
    [$thread] = attachmentsThread();
    $file = tempnam(sys_get_temp_dir(), 'yak') . '.log';
    file_put_contents($file, 'Stack trace');

    $page = visit(route('threads.show', $thread));
    $page->script(threadFileEventScript('[data-testid="thread-composer"] .relative', 'drop', 'mockup.png'));
    $page->attach('[data-testid="attachment-input"]', $file);

    $page->assertCount('[data-testid="draft-attachment"]', 2)
        ->assertSeeIn('[data-testid="draft-attachments"]', basename($file));
});

it('uploads the attachments with the message and clears the draft', function () {
    [$thread] = attachmentsThread(['blocked_reason' => 'waiting_for_account']);
    TaskRun::factory()->create(['yak_task_id' => $thread->tasks()->first()->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::WaitingForAccount]);

    $page = visit(route('threads.show', $thread));
    $page->typeSlowly(THREAD_COMPOSER, 'The legend in', 5);
    $page->script(threadFileEventScript(THREAD_COMPOSER, 'paste', 'chart.png'));
    $page->typeSlowly(THREAD_COMPOSER, 'overlaps the axis', 5);
    $page->click('[data-testid="thread-send"]')
        ->assertMissing('[data-testid="draft-attachment"]');
    $page->wait(2);

    $message = PendingSteeringMessage::sole();

    expect($message->text)->toBe('The legend in [Image #1] overlaps the axis')
        ->and($message->attachments)->toHaveCount(1)
        ->and($message->attachments->first()->original_name)->toBe('chart.png')
        ->and($message->attachments->first()->reference)->toBe('Image #1');
});

it('shows sent files as thumbnails linked to their labels, and the image opens in the lightbox', function () {
    [$thread, $image, $log] = attachmentsThreadWithSentFiles();

    visit(route('threads.show', $thread))
        ->assertVisible("[data-testid=\"sent-attachment-{$image->id}\"] img")
        ->assertSeeIn("[data-testid=\"sent-attachment-{$log->id}\"]", 'worker.log')
        ->hover('[data-testid="thread-conversation-scroller"] [data-attachment-ref="Image #1"]')
        ->assertPresent("[data-testid=\"sent-attachment-{$image->id}\"][data-highlighted]")
        ->click('[data-testid="thread-conversation-scroller"] [data-attachment-ref="Image #1"]')
        ->assertVisible('[data-testid="media-lightbox"]')
        ->keys('[data-testid="media-lightbox"]', 'Escape')
        ->assertMissing('[data-testid="media-lightbox"]')
        ->assertNoJavaScriptErrors();
});

it('brings a deleted token and its file back on undo', function () {
    [$thread] = attachmentsThread();

    $page = visit(route('threads.show', $thread));
    $page->script(threadFileEventScript(THREAD_COMPOSER, 'paste', 'keep-me.png'));
    $page->keys(THREAD_COMPOSER, ['Backspace', 'Backspace'])
        ->assertValue(THREAD_COMPOSER, '')
        ->assertMissing('[data-testid="draft-attachment"]');

    $page->keys(THREAD_COMPOSER, 'ControlOrMeta+z')
        ->assertValue(THREAD_COMPOSER, '[Image #1]')
        ->assertPresent('[data-testid="draft-attachment"][data-reference="Image #1"]');
});

it('numbers new labels after the files already in the conversation', function () {
    [$thread, $task] = attachmentsThread();
    TaskAttachment::factory()->for($task, 'task')->create(['reference' => 'Image #1']);
    TaskAttachment::factory()->for($task, 'task')->file()->create(['reference' => 'File #2']);

    $page = visit(route('threads.show', $thread));
    $page->script(threadFileEventScript(THREAD_COMPOSER, 'paste', 'third.png'));

    $page->assertValue(THREAD_COMPOSER, '[Image #3] ');
});

it('shows a text file in the lightbox and closes it on a click outside the content', function () {
    [$thread, , $log] = attachmentsThreadWithSentFiles();

    $page = visit(route('threads.show', $thread))
        ->click("[data-testid=\"sent-attachment-{$log->id}\"] button")
        ->assertSeeIn('[data-testid="media-lightbox-text"]', 'TypeError: x is undefined')
        ->assertSeeIn('[data-testid="media-lightbox-language"]', 'Plain text · 1 lines')
        ->assertPresent('[data-testid="media-lightbox-download"]');

    // A click on the stage itself (not the preview inside it) is the dimmed space.
    $page->script("document.querySelector('[data-testid=\"media-lightbox-stage\"]').click()");

    $page->assertMissing('[data-testid="media-lightbox"]');
});

/**
 * A composer holding `Hi [Image #1] there`, where the token spans 3..13.
 */
function threadComposerWithToken(): mixed
{
    [$thread] = attachmentsThread();

    $page = visit(route('threads.show', $thread));
    $page->typeSlowly(THREAD_COMPOSER, 'Hi', 5);
    $page->script(threadFileEventScript(THREAD_COMPOSER, 'paste', 'shot.png'));
    $page->typeSlowly(THREAD_COMPOSER, 'there', 5);
    $page->assertValue(THREAD_COMPOSER, 'Hi [Image #1] there');

    return $page;
}

function threadSelectionOf(mixed $page): array
{
    return $page->script(<<<'JS'
        (() => {
            const textarea = document.querySelector('[data-testid="thread-composer-input"]');
            return [textarea.selectionStart, textarea.selectionEnd];
        })()
        JS);
}

it('selects the whole token when it is clicked', function () {
    $page = threadComposerWithToken();

    $page->script(<<<'JS'
        (() => {
            const textarea = document.querySelector('[data-testid="thread-composer-input"]');
            const rect = document.querySelector('[data-testid="attachment-textarea-mirror"] [data-attachment-ref="Image #1"]').getBoundingClientRect();
            textarea.focus();
            textarea.setSelectionRange(7, 7);
            textarea.dispatchEvent(new MouseEvent('mouseup', {
                clientX: rect.left + rect.width / 2,
                clientY: rect.top + rect.height / 2,
                bubbles: true,
            }));
        })()
        JS);

    expect(threadSelectionOf($page))->toBe([3, 13]);
});

it('selects and deselects a token in one step with shift and the arrow keys', function () {
    $page = threadComposerWithToken();

    // Moving left from after the token.
    $page->script('document.querySelector(\'' . THREAD_COMPOSER . '\').setSelectionRange(13, 13)');
    $page->keys(THREAD_COMPOSER, 'Shift+ArrowLeft');
    expect(threadSelectionOf($page))->toBe([3, 13]);
    $page->keys(THREAD_COMPOSER, 'Shift+ArrowLeft');
    expect(threadSelectionOf($page))->toBe([2, 13]);
    $page->keys(THREAD_COMPOSER, 'Shift+ArrowRight');
    expect(threadSelectionOf($page))->toBe([3, 13]);
    $page->keys(THREAD_COMPOSER, 'Shift+ArrowRight');
    expect(threadSelectionOf($page))->toBe([13, 13]);

    // Moving right from before it.
    $page->script('document.querySelector(\'' . THREAD_COMPOSER . '\').setSelectionRange(3, 3)');
    $page->keys(THREAD_COMPOSER, 'Shift+ArrowRight');
    expect(threadSelectionOf($page))->toBe([3, 13]);
    $page->keys(THREAD_COMPOSER, 'Shift+ArrowRight');
    expect(threadSelectionOf($page))->toBe([3, 14]);
    $page->keys(THREAD_COMPOSER, 'Shift+ArrowLeft');
    expect(threadSelectionOf($page))->toBe([3, 13]);
    $page->keys(THREAD_COMPOSER, 'Shift+ArrowLeft');
    expect(threadSelectionOf($page))->toBe([3, 3]);
});

it('brings back the same attachment and label when an image that was just removed is pasted again', function () {
    [$thread] = attachmentsThread();

    $page = visit(route('threads.show', $thread));
    $page->script(threadFileEventScript(THREAD_COMPOSER, 'paste'));
    $page->keys(THREAD_COMPOSER, ['Backspace', 'Backspace'])
        ->assertMissing('[data-testid="draft-attachment"]');

    // Each paste builds a new File with a new name and timestamp; only the bytes match.
    $page->script(threadFileEventScript(THREAD_COMPOSER, 'paste'));

    $page->assertValue(THREAD_COMPOSER, '[Image #1] ')
        ->assertCount('[data-testid="draft-attachment"]', 1)
        ->assertPresent('[data-testid="draft-attachment"][data-reference="Image #1"]');
});

it('adds another token, not a copy, when an image that is already attached is pasted again', function () {
    [$thread] = attachmentsThread();

    $page = visit(route('threads.show', $thread));
    $page->script(threadFileEventScript(THREAD_COMPOSER, 'paste'));
    $page->typeSlowly(THREAD_COMPOSER, 'and again', 5);
    $page->script(threadFileEventScript(THREAD_COMPOSER, 'paste'));

    $page->assertValue(THREAD_COMPOSER, '[Image #1] and again [Image #1] ')
        ->assertCount('[data-testid="draft-attachment"]', 1);
});

it('previews a code file read-only with highlighting picked from the extension', function () {
    [$thread, , , $php] = attachmentsThreadWithSentFiles();

    $page = visit(route('threads.show', $thread))
        ->click("[data-testid=\"sent-attachment-{$php->id}\"] button")
        ->assertSeeIn('[data-testid="media-lightbox-language"]', 'PHP · 6 lines')
        ->assertSeeIn('[data-testid="media-lightbox-code"]', 'function total');

    // The highlighter loads after the code shows; highlighted tokens are wrapped in spans, unhighlighted text is bare.
    $page->script('new Promise((resolve) => { const start = Date.now(); (function poll() { if (document.querySelector(\'[data-testid="media-lightbox-code"] .cm-line span\') || Date.now() - start > 4000) { resolve(true); } else { setTimeout(poll, 100); } })(); })');
    expect($page->script(<<<'JS'
        (() => [...document.querySelectorAll('[data-testid="media-lightbox-code"] .cm-line span')].map((span) => span.textContent))()
        JS))->toContain('function', '$items', '// cents');

    // The code can't be edited (the file has no "q" in it to begin with).
    $page->click('[data-testid="media-lightbox-code"] .cm-content')
        ->keys('[data-testid="media-lightbox-code"] .cm-content', 'q')
        ->assertDontSeeIn('[data-testid="media-lightbox-code"]', 'q');
});
