<?php

use App\Livewire\Tasks\TaskDetail;
use App\Models\Artifact;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Storage::fake('artifacts');
});

/**
 * @return array{0: YakTask, 1: Artifact}
 */
function taskWithChapters(array $chapters): array
{
    $task = YakTask::factory()->success()->create();
    $recording = Artifact::factory()->for($task, 'task')->video()->create();
    Artifact::factory()->for($task, 'task')->videoCut()->create();
    Storage::disk('artifacts')->put("{$task->id}/chapters.json", json_encode($chapters));
    Artifact::factory()->for($task, 'task')->create([
        'type' => 'file',
        'role' => 'chapters',
        'filename' => 'chapters.json',
        'disk_path' => "{$task->id}/chapters.json",
    ]);

    return [$task, $recording];
}

test('chapters are read from the chapters artifact', function () {
    [$task] = taskWithChapters([
        ['title' => 'Geography levels', 'startSeconds' => 4, 'shots' => [
            ['id' => 'intro', 'startSeconds' => 4, 'say' => 'Here are the geography levels.'],
        ]],
        ['title' => 'Published', 'startSeconds' => 70, 'shots' => [
            ['id' => 'done', 'startSeconds' => 70, 'say' => 'And the page is live for reviewers.'],
        ]],
    ]);

    $chapters = Livewire::test(TaskDetail::class, ['task' => $task])->instance()->chapters();

    expect($chapters)->toHaveCount(2)
        ->and($chapters[0]['title'])->toBe('Geography levels')
        ->and($chapters[0]['shots'][0]['say'])->toBe('Here are the geography levels.');
});

test('a missing chapters artifact yields no chapters', function () {
    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->videoCut()->create();

    expect(Livewire::test(TaskDetail::class, ['task' => $task])->instance()->chapters())->toBe([]);
});

test('malformed chapters json yields no chapters', function () {
    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->videoCut()->create();
    Storage::disk('artifacts')->put("{$task->id}/chapters.json", 'not json');
    Artifact::factory()->for($task, 'task')->create([
        'type' => 'file',
        'role' => 'chapters',
        'filename' => 'chapters.json',
        'disk_path' => "{$task->id}/chapters.json",
    ]);

    expect(Livewire::test(TaskDetail::class, ['task' => $task])->instance()->chapters())->toBe([]);
});

test('the lightbox lists the chapters with their timestamps', function () {
    [$task, $recording] = taskWithChapters([
        ['title' => 'Geography levels', 'startSeconds' => 4, 'shots' => [
            ['id' => 'intro', 'startSeconds' => 4, 'say' => 'Here are the geography levels.'],
        ]],
        ['title' => 'Published', 'startSeconds' => 70, 'shots' => [
            ['id' => 'done', 'startSeconds' => 70, 'say' => 'And the page is live for reviewers.'],
        ]],
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openMediaLightbox', $recording->id)
        ->assertSeeHtml('data-testid="walkthrough-chapters"')
        ->assertSee('Geography levels')
        ->assertSee('0:04')
        ->assertSee('1:10');
});

test('timestamps format as minutes and padded seconds', function () {
    expect(TaskDetail::formatTimestamp(0.0))->toBe('0:00')
        ->and(TaskDetail::formatTimestamp(4.6))->toBe('0:04')
        ->and(TaskDetail::formatTimestamp(70.0))->toBe('1:10')
        ->and(TaskDetail::formatTimestamp(3599.0))->toBe('59:59');
});

test('the lightbox lists every narration line with its timestamp', function () {
    [$task, $recording] = taskWithChapters([
        ['title' => 'Geography levels', 'startSeconds' => 4, 'shots' => [
            ['id' => 'intro', 'startSeconds' => 4, 'say' => 'Here are the geography levels.'],
            ['id' => 'zoom', 'startSeconds' => 31, 'say' => 'ZIP-level demographics load underneath.'],
        ]],
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openMediaLightbox', $recording->id)
        ->assertSeeHtml('data-testid="walkthrough-transcript"')
        ->assertSeeHtml('data-testid="walkthrough-copy-transcript"')
        ->assertSee('Here are the geography levels.')
        ->assertSee('ZIP-level demographics load underneath.')
        ->assertSee('0:31');
});

test('there is no transcript block without chapters', function () {
    $task = YakTask::factory()->success()->create();
    $recording = Artifact::factory()->for($task, 'task')->video()->create();
    Artifact::factory()->for($task, 'task')->videoCut()->create();

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openMediaLightbox', $recording->id)
        ->assertDontSeeHtml('data-testid="walkthrough-transcript"');
});

test('a ?t= deep link opens the lightbox on the cut and passes the seek point', function () {
    [$task] = taskWithChapters([
        ['title' => 'Geography levels', 'startSeconds' => 4, 'shots' => [
            ['id' => 'intro', 'startSeconds' => 4, 'say' => 'Here are the geography levels.'],
        ]],
    ]);

    Livewire::withUrlParams(['t' => 31])
        ->test(TaskDetail::class, ['task' => $task])
        ->assertSet('lightboxOpen', true)
        ->assertSet('lightboxArtifactId', $task->artifacts()->cut()->first()->id)
        ->assertSeeHtml('seekTo: 31');
});

test('a ?t= deep link without a cut leaves the lightbox closed', function () {
    $task = YakTask::factory()->success()->create();

    Livewire::withUrlParams(['t' => 12])
        ->test(TaskDetail::class, ['task' => $task])
        ->assertSet('lightboxOpen', false);
});

test('no ?t= leaves the player without a seek point', function () {
    [$task, $recording] = taskWithChapters([
        ['title' => 'Geography levels', 'startSeconds' => 4, 'shots' => [
            ['id' => 'intro', 'startSeconds' => 4, 'say' => 'Here are the geography levels.'],
        ]],
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('openMediaLightbox', $recording->id)
        ->assertSeeHtml('seekTo: null');
});
