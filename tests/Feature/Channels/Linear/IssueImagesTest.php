<?php

use App\Channels\Linear\IssueImages;
use App\Enums\TaskStatus;
use App\Jobs\RunFollowUpJob;
use App\Models\PendingSteeringMessage;
use App\Models\Repository;
use App\Models\TaskAttachment;
use App\Models\YakTask;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

const LINEAR_UPLOAD = 'https://uploads.linear.app/workspace/org/image-one';

function linearImageNode(string $source = LINEAR_UPLOAD . '?signature=abc', string $alt = 'junk-inbox.png'): string
{
    return '<linear-image>' . json_encode(['type' => 'image', 'attrs' => ['src' => $source, 'alt' => $alt]]) . '</linear-image>';
}

function pngBytes(): string
{
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function bmpBytes(): string
{
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagebmp($image);

    return (string) ob_get_clean();
}

function linearTaskWithImage(string $name = 'junk-inbox.png'): YakTask
{
    return YakTask::factory()->create([
        'source' => 'linear',
        'description' => 'Junk piles up. [Image #1]',
        'context' => json_encode(['linear_issue_id' => 'issue-1', 'linear_images' => [['reference' => 'Image #1', 'url' => LINEAR_UPLOAD, 'name' => $name]]]),
    ]);
}

beforeEach(function () {
    Storage::fake('artifacts');
    enableLinearChannel();
    linearConnection();
});

it('turns both Linear image formats into labels', function () {
    $text = 'One ' . linearImageNode() . ' two ![second.png](https://uploads.linear.app/workspace/org/image-two) again ![](' . LINEAR_UPLOAD . ')';

    $parsed = (new IssueImages)->extract($text);

    expect($parsed['text'])->toBe('One [Image #1] two [Image #2] again [Image #1]')
        ->and($parsed['images'])->toBe([
            ['reference' => 'Image #1', 'url' => LINEAR_UPLOAD, 'name' => 'junk-inbox.png'],
            ['reference' => 'Image #2', 'url' => 'https://uploads.linear.app/workspace/org/image-two', 'name' => 'second.png'],
        ]);
});

it('never treats another host as a Linear upload', function () {
    $parsed = (new IssueImages)->extract(linearImageNode('https://uploads.linear.app.evil.test/steal', 'Chart') . ' ![Logo](https://example.com/logo.png)');

    expect($parsed['images'])->toBe([])
        ->and($parsed['text'])->toBe('[Chart](https://uploads.linear.app.evil.test/steal) ![Logo](https://example.com/logo.png)');
});

it('keeps the text readable when a node is malformed', function () {
    expect((new IssueImages)->extract('Before <linear-image>{not json</linear-image> after')['text'])->toBe('Before  after');
});

it('downloads a task image with the Linear token and attaches it under its label', function () {
    Http::fake(['uploads.linear.app/*' => Http::response(pngBytes(), 200, ['Content-Type' => 'image/png'])]);
    $task = linearTaskWithImage();

    (new IssueImages)->storeForTask($task);

    $attachment = TaskAttachment::query()->where('yak_task_id', $task->id)->sole();

    expect($attachment->reference)->toBe('Image #1')
        ->and($attachment->original_name)->toBe('junk-inbox.png')
        ->and($attachment->mime_type)->toBe('image/png')
        ->and($task->fresh()->description)->toBe('Junk piles up. [Image #1]')
        ->and(json_decode((string) $task->fresh()->context, true))->not->toHaveKey('linear_images');

    Storage::disk('artifacts')->assertExists($attachment->disk_path);
    Http::assertSent(fn (Request $request): bool => $request->url() === LINEAR_UPLOAD && $request->hasHeader('Authorization'));
});

it('does not store an image twice when the job runs again', function () {
    Http::fake(['uploads.linear.app/*' => Http::response(pngBytes())]);
    $task = linearTaskWithImage();
    TaskAttachment::storeContent('junk-inbox.png', pngBytes(), 'image/png', ['yak_task_id' => $task->id, 'reference' => 'Image #1']);

    (new IssueImages)->storeForTask($task);

    expect(TaskAttachment::query()->where('yak_task_id', $task->id)->count())->toBe(1);
    Http::assertNothingSent();
});

it('converts an image format a browser cannot show to PNG', function () {
    Http::fake(['uploads.linear.app/*' => Http::response(bmpBytes())]);
    $task = linearTaskWithImage('capture.bmp');

    (new IssueImages)->storeForTask($task);

    $attachment = TaskAttachment::query()->where('yak_task_id', $task->id)->sole();

    expect($attachment->mime_type)->toBe('image/png')
        ->and($attachment->original_name)->toBe('capture.png')
        ->and(getimagesizefromstring((string) Storage::disk('artifacts')->get($attachment->disk_path))['mime'])->toBe('image/png');
});

it('says so in the text when an image cannot be stored', function (Closure $response) {
    Http::fake(['uploads.linear.app/*' => $response()]);
    $task = linearTaskWithImage();

    (new IssueImages)->storeForTask($task);

    expect(TaskAttachment::query()->where('yak_task_id', $task->id)->count())->toBe(0)
        ->and($task->fresh()->description)->toBe('Junk piles up. _(Image not available: junk-inbox.png)_');
})->with([
    'refused' => fn () => fn () => Http::response('', 401),
    'svg' => fn () => fn () => Http::response('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
]);

it('keeps the image JSON out of the task text for a new issue', function () {
    $secret = enableLinearChannel();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);
    Repository::factory()->default()->create();

    postLinearWebhook(agentSessionCreatedPayload(['description' => 'Junk piles up. ' . linearImageNode()]), $secret)->assertSuccessful();

    $task = YakTask::query()->sole();

    expect($task->description)->toContain('Junk piles up. [Image #1]')
        ->and($task->description)->not->toContain('linear-image')
        ->and(json_decode((string) $task->context, true)['linear_images'][0]['url'])->toBe(LINEAR_UPLOAD);
});

it('attaches the images of a follow-up reply to the new task', function () {
    $secret = enableLinearChannel();
    Queue::fake();
    Http::fake([
        'uploads.linear.app/*' => Http::response(pngBytes()),
        '*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]]),
    ]);
    $task = YakTask::factory()->create(['source' => 'linear', 'status' => TaskStatus::Success, 'linear_agent_session_id' => 'session-uuid-001', 'pr_url' => 'https://github.com/acme/app/pull/1']);

    postLinearWebhook((string) json_encode([
        'type' => 'AgentSessionEvent', 'action' => 'prompted', 'organizationId' => TEST_WORKSPACE_ID,
        'agentSession' => ['id' => 'session-uuid-001'],
        'agentActivity' => ['body' => 'Look at this ' . linearImageNode()],
    ]), $secret)->assertSuccessful();

    $followUp = YakTask::query()->where('parent_task_id', $task->id)->sole();

    expect($followUp->description)->toBe('Look at this [Image #1]')
        ->and(TaskAttachment::query()->where('yak_task_id', $followUp->id)->sole()->reference)->toBe('Image #1');
    Queue::assertPushed(RunFollowUpJob::class);
});

it('attaches the images of a reply sent during a run to the steering message', function () {
    $secret = enableLinearChannel();
    Queue::fake();
    Http::fake([
        'uploads.linear.app/*' => Http::response(pngBytes()),
        '*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]]),
    ]);
    YakTask::factory()->create(['source' => 'linear', 'status' => TaskStatus::Running, 'linear_agent_session_id' => 'session-uuid-001']);

    postLinearWebhook((string) json_encode([
        'type' => 'AgentSessionEvent', 'action' => 'prompted', 'organizationId' => TEST_WORKSPACE_ID,
        'agentSession' => ['id' => 'session-uuid-001'],
        'agentActivity' => ['body' => 'Also this ' . linearImageNode()],
    ]), $secret)->assertSuccessful();

    $message = PendingSteeringMessage::query()->sole();

    expect($message->text)->toBe('Also this [Image #1]')
        ->and($message->attachments()->sole()->reference)->toBe('Image #1');
});
