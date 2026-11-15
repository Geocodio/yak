<?php

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Models\Artifact;
use App\Models\Repository;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Tasks\AppMediaLinks;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

const MEDIA_PR_ASSET = 'f38ea4ee-97ce-48fc-a48e-a049c20f7cd4';

beforeEach(function () {
    config(['app.url' => 'https://yak.example.com']);
    URL::forceRootUrl('https://yak.example.com');
    URL::forceScheme('https');
    Storage::fake('artifacts');
    Repository::factory()->create(['slug' => 'example/api', 'github_full_name' => 'example/api']);
    $this->user = User::factory()->create(['name' => 'Test person']);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function mediaTask(User $user, array $attributes = []): YakTask
{
    return YakTask::factory()->create($attributes + [
        'public_id' => 'k3m9p2qa', 'repo' => 'example/api', 'mode' => TaskMode::Fix, 'status' => TaskStatus::Success,
        'description' => 'Upgrade the payments SDK', 'started_by_user_id' => $user->id, 'thread_id' => null,
    ]);
}

function signedExpiryMinutes(string $url): int
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (int) round(((int) $query['expires'] - now()->timestamp) / 60);
}

it('rewrites session-only links to signed app links', function () {
    $task = mediaTask($this->user, ['mode' => TaskMode::Review, 'context' => json_encode([
        'pr_number' => 42, 'body' => '![Screenshot](https://github.com/user-attachments/assets/' . MEDIA_PR_ASSET . ')',
    ])]);
    $response = $this->getJson(appTaskPath($task), phoneHeadersFor($this->user))->assertOk();

    $image = $response->json('thread.0.bodyHtml');
    expect($image)->toContain('https://yak.example.com/app-media/tasks/k3m9p2qa/pr-images/' . MEDIA_PR_ASSET . '?')
        ->and($image)->toContain('signature=');
    expect(str_replace('\\/', '/', $response->getContent()))->not->toMatch('#(?<!app-media)/tasks/[a-z0-9]+/pr-images#');

    $fixTask = mediaTask($this->user, ['public_id' => 'n4q8r2xd']);
    $attachment = TaskAttachment::factory()->for($fixTask, 'task')->create(['original_name' => 'before.png']);
    $response = $this->getJson(appTaskPath($fixTask), phoneHeadersFor($this->user))->assertOk();
    $url = $response->json('thread.0.attachments.0.url');
    expect($url)->toStartWith("https://yak.example.com/app-media/task-attachments/{$attachment->id}?")
        ->and(signedExpiryMinutes($url))->toBe(30);

    expect($response->json('thread.0.attachments.0.downloadUrl'))->toContain('download=1')
        ->and(str_replace('\\/', '/', $response->getContent()))->not->toMatch('#(?<!app-media)/task-attachments/#');
});

it('serves a signed attachment without a session', function () {
    $task = mediaTask($this->user);
    Storage::disk('artifacts')->put('attachments/a/before.png', 'png-bytes');
    $attachment = TaskAttachment::factory()->for($task, 'task')->create(['disk_path' => 'attachments/a/before.png', 'original_name' => 'before.png', 'mime_type' => 'image/png']);

    $this->get((new AppMediaLinks($task))->forAttachment($attachment))
        ->assertOk()->assertHeader('Content-Type', 'image/png');
});

it('refuses an unsigned, tampered or expired link', function () {
    $task = mediaTask($this->user);
    Storage::disk('artifacts')->put('attachments/a/before.png', 'png-bytes');
    $attachment = TaskAttachment::factory()->for($task, 'task')->create(['disk_path' => 'attachments/a/before.png']);
    $other = TaskAttachment::factory()->for($task, 'task')->create(['disk_path' => 'attachments/a/before.png']);
    $url = (new AppMediaLinks($task))->forAttachment($attachment);

    $this->get("https://yak.example.com/app-media/task-attachments/{$attachment->id}")->assertForbidden();
    $this->get(str_replace("task-attachments/{$attachment->id}", "task-attachments/{$other->id}", $url))->assertForbidden()->assertDontSee('png-bytes');
    $this->travel(31)->minutes();
    $this->get($url)->assertForbidden()->assertDontSee('png-bytes');
});

it('rewrites research artifact links to signed file links', function () {
    $task = mediaTask($this->user, ['mode' => TaskMode::Research, 'result_summary' => 'Done.']);
    Storage::disk('artifacts')->put('research.html', '<p>report</p>');
    Artifact::factory()->research()->create(['yak_task_id' => $task->id]);

    $response = $this->getJson(appTaskPath($task), phoneHeadersFor($this->user))->assertOk();

    $artifactUrl = $response->json('task.researchArtifactUrl') ?? $response->json('researchArtifactUrl');
    expect($artifactUrl)->toStartWith('https://yak.example.com/artifacts/k3m9p2qa/research.html?')
        ->and(signedExpiryMinutes($artifactUrl))->toBe(30)
        ->and($response->getContent())->not->toContain('/viewer/');

    // A fresh client: nobody is signed in.
    $this->app['auth']->forgetGuards();
    $file = $this->get($artifactUrl)->assertOk()->baseResponse->getFile();
    expect(file_get_contents($file->getPathname()))->toBe('<p>report</p>');
    $this->travel(31)->minutes();
    $this->get($artifactUrl)->assertForbidden();
});

it('leaves the web body unchanged', function () {
    $task = mediaTask($this->user, ['mode' => TaskMode::Research]);
    Storage::disk('artifacts')->put('research.html', '<p>report</p>');
    Artifact::factory()->research()->create(['yak_task_id' => $task->id]);

    $this->actingAs($this->user)->get(route('tasks.show', $task))
        ->assertInertia(fn ($page) => $page->where('task.researchArtifactUrl', route('artifacts.viewer', ['task' => 'k3m9p2qa', 'filename' => 'research.html'])));
});
