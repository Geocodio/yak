<?php

use App\Enums\ThreadSandboxState;
use App\Enums\TurnMessageState;
use App\Enums\TurnState;
use App\Models\TaskAttachment;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Tasks\AppMediaLinks;
use App\Services\Threads\ThreadTurns;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\ContractFixture;

require_once __DIR__ . '/../../../../Support/ThreadTurnHelpers.php';

const ATTACHMENT_LINKS_VOLATILE = [
    'thread.id', 'thread.rootThreadId', 'thread.preview.targets.*.threadId', 'messages.*.id', 'messages.*.uuid', 'messages.*.attachments.*.id', 'messages.*.attachmentIds.*',
    'messages.*.attachments.*.url', 'messages.*.attachments.*.downloadUrl', 'messages.*.bodyHtml', 'messages.*.sentAt', 'question.taskId', 'teleport.command', 'teleport.link',
];

beforeEach(function () {
    config(['app.url' => 'https://yak.example.com']);
    URL::forceRootUrl('https://yak.example.com');
    URL::forceScheme('https');
    Storage::fake('artifacts');
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
});

/**
 * A thread d0e515ef with a sent message carrying two files and a queued message carrying one, and a second thread
 * a1b2c3d4 with a file of its own.
 *
 * @return array{0: User, 1: Thread, 2: Thread, 3: list<TaskAttachment>, 4: TaskAttachment, 5: TaskAttachment}
 */
function attachmentLinksWorld(): array
{
    $user = User::factory()->create(['name' => 'Test person']);
    [$thread, $run] = runningTurnFor(['turn_state' => TurnState::Completed, 'finished_at' => now()->subHour()]);
    $thread->claudeAccount->forceFill(['name' => 'Example account'])->save();
    $thread->forceFill(['public_id' => 'd0e515ef', 'title' => 'Retry failed uploads', 'name' => 'retry-failed-uploads', 'repo' => 'example/api', 'created_by_user_id' => $user->id, 'owner_user_id' => $user->id, 'sandbox_state' => ThreadSandboxState::None, 'model' => 'sonnet', 'runner_model' => 'sonnet'])->save();
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'started_by_user_id' => $user->id]);
    $run->forceFill(['yak_task_id' => $task->id, 'driver_user_id' => $user->id])->save();
    $message = ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'state' => TurnMessageState::Completed, 'text' => 'See Image #1 and File #2']);

    $files = [];
    foreach ([['screenshot.png', 'Image #1', 'image/png'], ['report.pdf', 'File #2', 'application/pdf']] as [$name, $reference, $mime]) {
        $file = TaskAttachment::factory()->file($name, $mime)->create(['yak_task_id' => $task->id, 'thread_turn_message_id' => $message->id, 'reference' => $reference, 'size_bytes' => 9]);
        Storage::disk('artifacts')->put($file->disk_path, "bytes-{$name}");
        $files[] = $file;
    }

    $queued = app(ThreadTurns::class)->steer($thread, 'Also File #3', $user, null, source: 'thread', isQueueOnly: true);
    $queuedFile = TaskAttachment::factory()->file('notes.txt')->create(['yak_task_id' => null, 'pending_steering_message_id' => $queued->id, 'reference' => 'File #3', 'size_bytes' => 9]);
    Storage::disk('artifacts')->put($queuedFile->disk_path, 'bytes-notes');

    $other = Thread::factory()->create(['public_id' => 'a1b2c3d4', 'created_by_user_id' => $user->id, 'owner_user_id' => $user->id]);
    $otherTask = YakTask::factory()->create(['thread_id' => $other->id, 'started_by_user_id' => $user->id]);
    $foreign = TaskAttachment::factory()->create(['yak_task_id' => $otherTask->id]);

    return [$user, $thread, $other, $files, $queuedFile, $foreign];
}

it('signs every message attachment link for 30 minutes', function () {
    [$user, $thread, , $files, $queuedFile] = attachmentLinksWorld();

    $response = $this->getJson(appThreadPath($thread), phoneHeadersFor($user))->assertOk();
    $attachments = collect($response->json('messages'))->flatMap(fn (array $message): array => $message['attachments']);

    expect($attachments)->toHaveCount(3);
    foreach ($attachments as $attachment) {
        expect($attachment['url'])->toStartWith("https://yak.example.com/app-media/task-attachments/{$attachment['id']}?")
            ->and($attachment['url'])->toContain('signature=')
            ->and($attachment['downloadUrl'])->toContain('download=1')
            ->and($attachment['downloadUrl'])->toContain('signature=');
        parse_str((string) parse_url($attachment['url'], PHP_URL_QUERY), $query);
        expect((int) $query['expires'])->toBe(now()->addMinutes(30)->timestamp);
    }

    expect($attachments->pluck('id')->sort()->values()->all())->toBe(collect([...$files, $queuedFile])->pluck('id')->sort()->values()->all())
        ->and(str_replace('\\/', '/', $response->getContent()))->not->toMatch('#(?<!app-media)/task-attachments/#');
});

it('opens a signed link with no session and no token', function () {
    [$user, $thread, , $files] = attachmentLinksWorld();
    $url = $this->getJson(appThreadPath($thread), phoneHeadersFor($user))->assertOk()->json('messages.0.attachments.0.url');

    $this->app['auth']->forgetGuards();
    $this->get($url)->assertOk()->assertStreamedContent('bytes-screenshot.png');
    $this->get(strtok($url, '?'))->assertForbidden();

    $this->travel(31)->minutes();
    $this->get($url)->assertForbidden();
});

it('signs only this thread\'s files', function () {
    [$user, $thread, $other, , , $foreign] = attachmentLinksWorld();
    $session = $foreign->url();
    $task = $thread->tasks()->first();
    ThreadTurnMessage::query()->where('text', 'See Image #1 and File #2')->update(['text' => "Look at {$session}"]);

    $html = $this->getJson(appThreadPath($thread), phoneHeadersFor($user))->assertOk()->json('messages.0.bodyHtml');
    expect(str_replace('&amp;', '&', $html))->toContain(explode('?', $session)[0])->and($html)->not->toContain('app-media');

    $links = AppMediaLinks::forThread($thread, [$foreign->id]);
    expect($links->rewrite($session))->toBe($session)
        ->and(AppMediaLinks::forThread($other, [$foreign->id])->rewrite($session))->toContain('app-media')
        ->and($task)->not->toBeNull();
});

it('leaves the editable message text untouched', function () {
    [$user, $thread, , , , $foreign] = attachmentLinksWorld();
    $own = $thread->tasks()->first()->attachments()->first();
    $text = "Look at {$own->url()}";
    ThreadTurnMessage::query()->where('text', 'See Image #1 and File #2')->update(['text' => $text]);

    $message = $this->getJson(appThreadPath($thread), phoneHeadersFor($user))->assertOk()->json('messages.0');

    expect($message['text'])->toBe($text)
        ->and($message['bodyHtml'])->toContain('app-media');
});

it('keeps the single-task links as they were', function () {
    $user = User::factory()->create();
    $task = YakTask::factory()->create(['started_by_user_id' => $user->id, 'thread_id' => null]);
    $attachment = TaskAttachment::factory()->for($task, 'task')->create();

    $rewritten = (new AppMediaLinks($task))->rewrite($attachment->url());

    expect($rewritten)->toBe(AppMediaLinks::forAttachment($attachment));
});

it('gives share recipients working links and strangers nothing', function () {
    [$user, $thread] = attachmentLinksWorld();
    $recipient = shareThreadWith($thread, $user);

    $url = $this->getJson(appThreadPath($thread), phoneHeadersFor($recipient))->assertOk()->assertJsonPath('canDrive', false)->json('messages.0.attachments.0.url');
    $this->app['auth']->forgetGuards();
    $this->get($url)->assertOk();

    $this->getJson(appThreadPath($thread), phoneHeadersFor(User::factory()->create()))->assertNotFound();

    $thread->shares()->delete();
    $this->getJson(appThreadPath($thread), phoneHeadersFor($recipient))->assertNotFound();
});

it('keeps the web thread page as it was', function () {
    [$user, $thread] = attachmentLinksWorld();

    $this->actingAs($user)->get(route('threads.show', $thread->public_id))
        ->assertInertia(function ($page) {
            $json = str_replace('\\/', '/', json_encode($page->toArray()['props']));
            expect($json)->toContain('/task-attachments/')->and($json)->not->toContain('app-media/');
        });
});

it('writes the thread-attachments fixture', function () {
    [$user, $thread] = attachmentLinksWorld();

    $response = $this->getJson(appThreadPath($thread), phoneHeadersFor($user))->assertOk();
    ContractFixture::assertMatches('app', 'thread-attachments', $response->json(), ATTACHMENT_LINKS_VOLATILE);
});
