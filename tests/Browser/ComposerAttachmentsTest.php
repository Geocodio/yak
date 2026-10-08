<?php

use App\Enums\TaskStatus;
use App\Models\PendingSteeringMessage;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Storage;

/**
 * Builds a 1x1 PNG `File` in the page and fires `eventType` at `selector`
 * carrying it, the way a screenshot paste or a Finder drag arrives.
 */
function fireFileEvent(string $selector, string $eventType, string $name = 'image.png'): string
{
    $constructor = $eventType === 'paste' ? 'ClipboardEvent' : 'DragEvent';
    $property = $eventType === 'paste' ? 'clipboardData' : 'dataTransfer';

    return <<<JS
        (() => {
            const bytes = Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), (c) => c.charCodeAt(0));
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

beforeEach(function () {
    Storage::fake('artifacts');
    $this->actingAs(User::factory()->create());
});

/**
 * Fire a mousemove at the centre of the first element matching `selector`.
 * The composer's chips sit behind the textarea, so this is how a pointer
 * resting on one arrives.
 */
function moveMouseOver(string $selector, string $target = '[data-testid="composer-input"]'): string
{
    return <<<JS
        (() => {
            const rect = document.querySelector('{$selector}').getBoundingClientRect();
            document.querySelector('{$target}').dispatchEvent(new MouseEvent('mousemove', {
                clientX: rect.left + rect.width / 2,
                clientY: rect.top + rect.height / 2,
                bubbles: true,
            }));
        })()
        JS;
}

test('a pasted screenshot attaches with a preview and a token, opens in the lightbox, and can be removed', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);

    $page = visit(route('tasks.show', $task));
    $page->script(fireFileEvent('[data-testid="composer-input"]', 'paste'));

    $page->assertVisible('[data-testid="draft-attachment"] img')
        ->assertAttributeContains('[data-testid="draft-attachment"] img', 'alt', 'pasted-image-')
        ->assertValue('[data-testid="composer-input"]', '[Image #1] ')
        ->assertSeeIn('[data-testid="attachment-textarea-mirror"] [data-attachment-ref="Image #1"]', '[Image #1]');

    $page->click('[data-testid="draft-attachment"] button[aria-label^="View"]')
        ->assertVisible('[data-testid="media-lightbox"]')
        ->keys('[data-testid="media-lightbox"]', 'Escape')
        ->assertMissing('[data-testid="media-lightbox"]');

    // Removing the tile takes its token out of the text.
    $page->hover('[data-testid="draft-attachment"]')
        ->click('[data-testid="attachment-remove"]')
        ->assertMissing('[data-testid="draft-attachment"]')
        ->assertValue('[data-testid="composer-input"]', '');
});

test('tokens sit where the files were added and backspace removes a whole token and its file', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);

    $page = visit(route('tasks.show', $task));
    $page->typeSlowly('[data-testid="composer-input"]', 'Before', 5);
    $page->script(fireFileEvent('[data-testid="composer-input"]', 'paste', 'before.png'));
    $page->typeSlowly('[data-testid="composer-input"]', 'after', 5);
    $page->script(fireFileEvent('[data-testid="composer-input"]', 'paste', 'after.png'));

    $page->assertValue('[data-testid="composer-input"]', 'Before [Image #1] after [Image #2] ')
        ->assertCount('[data-testid="draft-attachment"]', 2);

    // One backspace eats the trailing space, the next the whole token.
    $page->keys('[data-testid="composer-input"]', ['Backspace', 'Backspace'])
        ->assertValue('[data-testid="composer-input"]', 'Before [Image #1] after ')
        ->assertCount('[data-testid="draft-attachment"]', 1)
        ->assertPresent('[data-testid="draft-attachment"][data-reference="Image #1"]');
});

test('hovering a tile highlights its token and hovering a token highlights its tile', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);

    $page = visit(route('tasks.show', $task));
    $page->script(fireFileEvent('[data-testid="composer-input"]', 'paste', 'one.png'));
    $page->script(fireFileEvent('[data-testid="composer-input"]', 'paste', 'two.png'));

    $page->hover('[data-testid="draft-attachment"][data-reference="Image #2"]')
        ->assertPresent('[data-testid="attachment-textarea-mirror"] [data-attachment-ref="Image #2"][data-highlighted]')
        ->assertMissing('[data-testid="attachment-textarea-mirror"] [data-attachment-ref="Image #1"][data-highlighted]');

    $page->script(moveMouseOver('[data-testid="attachment-textarea-mirror"] [data-attachment-ref="Image #1"]'));

    $page->assertPresent('[data-testid="draft-attachment"][data-reference="Image #1"][data-highlighted]')
        ->assertMissing('[data-testid="draft-attachment"][data-reference="Image #2"][data-highlighted]');
});

test('dropping files on the composer attaches them and the picker adds more', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);
    $file = tempnam(sys_get_temp_dir(), 'yak') . '.log';
    file_put_contents($file, 'Stack trace');

    $page = visit(route('tasks.show', $task));
    $page->script(fireFileEvent('[data-testid="composer-input"]', 'drop', 'mockup.png'));
    $page->attach('[data-testid="attachment-input"]', $file);

    $page->assertCount('[data-testid="draft-attachment"]', 2)
        ->assertSeeIn('[data-testid="draft-attachments"]', basename($file));
});

test('sending a message uploads its attachments and shows them in the thread', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);

    $page = visit(route('tasks.show', $task));
    $page->typeSlowly('[data-testid="composer-input"]', 'The legend in', 5);
    $page->script(fireFileEvent('[data-testid="composer-input"]', 'paste', 'chart.png'));
    $page->typeSlowly('[data-testid="composer-input"]', 'overlaps the axis', 5);
    $page->click('[data-testid="composer-submit"]')
        ->assertMissing('[data-testid="draft-attachment"]');

    $message = PendingSteeringMessage::sole();

    expect($message->text)->toBe('The legend in [Image #1] overlaps the axis')
        ->and($message->attachments)->toHaveCount(1)
        ->and($message->attachments->first()->original_name)->toBe('chart.png')
        ->and($message->attachments->first()->reference)->toBe('Image #1');
});

test('attachments on a request show as thumbnails linked to their labels in the thread', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now(), 'description' => 'It breaks like [Image #1]']);
    $attachment = TaskAttachment::factory()->for($task, 'task')->create(['original_name' => 'bug.png', 'reference' => 'Image #1']);
    Storage::disk('artifacts')->put($attachment->disk_path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $file = TaskAttachment::factory()->for($task, 'task')->file('notes.txt')->create();

    visit(route('tasks.show', $task))
        ->assertVisible("[data-testid=\"sent-attachment-{$attachment->id}\"] img")
        ->assertSeeIn("[data-testid=\"sent-attachment-{$file->id}\"]", 'notes.txt')
        ->hover('.prose [data-attachment-ref="Image #1"]')
        ->assertPresent("[data-testid=\"sent-attachment-{$attachment->id}\"][data-highlighted]")
        ->click('.prose [data-attachment-ref="Image #1"]')
        ->assertVisible('[data-testid="media-lightbox"]');
});

test('undoing a deleted token brings its file back', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);

    $page = visit(route('tasks.show', $task));
    $page->script(fireFileEvent('[data-testid="composer-input"]', 'paste', 'keep-me.png'));
    $page->keys('[data-testid="composer-input"]', ['Backspace', 'Backspace'])
        ->assertValue('[data-testid="composer-input"]', '')
        ->assertMissing('[data-testid="draft-attachment"]');

    $page->keys('[data-testid="composer-input"]', 'ControlOrMeta+z')
        ->assertValue('[data-testid="composer-input"]', '[Image #1]')
        ->assertPresent('[data-testid="draft-attachment"][data-reference="Image #1"]');
});

test('labels carry on from the files already in the conversation', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);
    TaskAttachment::factory()->for($task, 'task')->create(['reference' => 'Image #1']);
    TaskAttachment::factory()->for($task, 'task')->file()->create(['reference' => 'File #2']);

    $page = visit(route('tasks.show', $task));
    $page->script(fireFileEvent('[data-testid="composer-input"]', 'paste', 'third.png'));

    $page->assertValue('[data-testid="composer-input"]', '[Image #3] ');
});

test('picking a clarification option keeps the files already placed in the reply', function () {
    $task = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingClarification,
        'clarification_options' => ['Convert in place', 'Keep both'],
        'clarification_expires_at' => now()->addHours(3),
    ]);

    $page = visit(route('tasks.show', $task));
    $page->script(fireFileEvent('[data-testid="composer-input"]', 'paste', 'context.png'));
    $page->click('[data-testid="clarification-option"] >> nth=0')
        ->assertValue('[data-testid="composer-input"]', 'Convert in place [Image #1]')
        ->assertCount('[data-testid="draft-attachment"]', 1);
});

test('text files preview in the lightbox, which closes on a click outside the content', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);
    $log = TaskAttachment::factory()->for($task, 'task')->file('worker.log')->create(['reference' => 'File #1']);
    Storage::disk('artifacts')->put($log->disk_path, 'TypeError: x is undefined');

    $page = visit(route('tasks.show', $task))
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
function composerWithToken(): mixed
{
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);

    $page = visit(route('tasks.show', $task));
    $page->typeSlowly('[data-testid="composer-input"]', 'Hi', 5);
    $page->script(fireFileEvent('[data-testid="composer-input"]', 'paste', 'shot.png'));
    $page->typeSlowly('[data-testid="composer-input"]', 'there', 5);
    $page->assertValue('[data-testid="composer-input"]', 'Hi [Image #1] there');

    return $page;
}

function selectionOf(mixed $page): array
{
    return $page->script(<<<'JS'
        (() => {
            const textarea = document.querySelector('[data-testid="composer-input"]');
            return [textarea.selectionStart, textarea.selectionEnd];
        })()
        JS);
}

test('clicking a token selects the whole of it', function () {
    $page = composerWithToken();

    $page->script(<<<'JS'
        (() => {
            const textarea = document.querySelector('[data-testid="composer-input"]');
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

    expect(selectionOf($page))->toBe([3, 13]);
});

test('shift and the arrow keys select and deselect a token in one step', function () {
    $page = composerWithToken();
    $input = '[data-testid="composer-input"]';

    // Moving left from after the token.
    $page->script("document.querySelector('{$input}').setSelectionRange(13, 13)");
    $page->keys($input, 'Shift+ArrowLeft');
    expect(selectionOf($page))->toBe([3, 13]);
    $page->keys($input, 'Shift+ArrowLeft');
    expect(selectionOf($page))->toBe([2, 13]);
    $page->keys($input, 'Shift+ArrowRight');
    expect(selectionOf($page))->toBe([3, 13]);
    $page->keys($input, 'Shift+ArrowRight');
    expect(selectionOf($page))->toBe([13, 13]);

    // Moving right from before it.
    $page->script("document.querySelector('{$input}').setSelectionRange(3, 3)");
    $page->keys($input, 'Shift+ArrowRight');
    expect(selectionOf($page))->toBe([3, 13]);
    $page->keys($input, 'Shift+ArrowRight');
    expect(selectionOf($page))->toBe([3, 14]);
    $page->keys($input, 'Shift+ArrowLeft');
    expect(selectionOf($page))->toBe([3, 13]);
    $page->keys($input, 'Shift+ArrowLeft');
    expect(selectionOf($page))->toBe([3, 3]);
});

test('pasting an image that was just removed brings back the same attachment and label', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);
    $input = '[data-testid="composer-input"]';

    $page = visit(route('tasks.show', $task));
    $page->script(fireFileEvent($input, 'paste'));
    $page->keys($input, ['Backspace', 'Backspace'])
        ->assertMissing('[data-testid="draft-attachment"]');

    // Each paste builds a new File with a new name and timestamp; only the bytes match.
    $page->script(fireFileEvent($input, 'paste'));

    $page->assertValue($input, '[Image #1] ')
        ->assertCount('[data-testid="draft-attachment"]', 1)
        ->assertPresent('[data-testid="draft-attachment"][data-reference="Image #1"]');
});

test('pasting an image that is already attached adds another token for it, not a copy', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);
    $input = '[data-testid="composer-input"]';

    $page = visit(route('tasks.show', $task));
    $page->script(fireFileEvent($input, 'paste'));
    $page->typeSlowly($input, 'and again', 5);
    $page->script(fireFileEvent($input, 'paste'));

    $page->assertValue($input, '[Image #1] and again [Image #1] ')
        ->assertCount('[data-testid="draft-attachment"]', 1);
});

test('code files preview read-only with highlighting picked from the extension', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);
    $php = TaskAttachment::factory()->for($task, 'task')->file('Invoice.php', 'text/x-php')->create(['reference' => 'File #1']);
    Storage::disk('artifacts')->put($php->disk_path, "<?php\n\nfunction total(\$items) {\n    return array_sum(\$items); // cents\n}\n");

    $page = visit(route('tasks.show', $task))
        ->click("[data-testid=\"sent-attachment-{$php->id}\"] button")
        ->assertSeeIn('[data-testid="media-lightbox-language"]', 'PHP · 6 lines')
        ->assertSeeIn('[data-testid="media-lightbox-code"]', 'function total');

    // Highlighted tokens are wrapped in spans; unhighlighted text is bare.
    expect($page->script(<<<'JS'
        (() => [...document.querySelectorAll('[data-testid="media-lightbox-code"] .cm-line span')].map((span) => span.textContent))()
        JS))->toContain('function', '$items', '// cents');

    // The code can't be edited (the file has no "q" in it to begin with).
    $page->click('[data-testid="media-lightbox-code"] .cm-content')
        ->keys('[data-testid="media-lightbox-code"] .cm-content', 'q')
        ->assertDontSeeIn('[data-testid="media-lightbox-code"]', 'q');
});
