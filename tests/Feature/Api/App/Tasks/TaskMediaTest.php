<?php

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Models\Artifact;
use App\Models\GitHubInstallationToken;
use App\Models\Repository;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Tasks\AppMediaLinks;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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
    preg_match('#src="([^"]+)"#', $image, $imageSource);
    expect(signedExpiryMinutes(html_entity_decode($imageSource[1])))->toBe(30);
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

    $this->get(AppMediaLinks::forAttachment($attachment))
        ->assertOk()->assertHeader('Content-Type', 'image/png')
        ->assertStreamedContent('png-bytes');
});

it('refuses an unsigned, tampered or expired link', function () {
    $task = mediaTask($this->user);
    Storage::disk('artifacts')->put('attachments/a/before.png', 'png-bytes');
    $attachment = TaskAttachment::factory()->for($task, 'task')->create(['disk_path' => 'attachments/a/before.png']);
    $other = TaskAttachment::factory()->for($task, 'task')->create(['disk_path' => 'attachments/a/before.png']);
    $url = AppMediaLinks::forAttachment($attachment);

    $this->get("https://yak.example.com/app-media/task-attachments/{$attachment->id}")->assertForbidden()->assertDontSee('png-bytes');
    $this->get(str_replace("task-attachments/{$attachment->id}", "task-attachments/{$other->id}", $url))->assertForbidden()->assertDontSee('png-bytes');
    $this->travel(31)->minutes();
    $this->get($url)->assertForbidden()->assertDontSee('png-bytes');
});

it('rewrites research artifact links to signed file links', function () {
    $task = mediaTask($this->user, ['mode' => TaskMode::Research, 'result_summary' => 'Done.', 'started_at' => now()->subMinute(), 'completed_at' => now()]);
    Storage::disk('artifacts')->put('research.html', '<p>report</p>');
    Artifact::factory()->research()->create(['yak_task_id' => $task->id]);

    $response = $this->getJson(appTaskPath($task), phoneHeadersFor($this->user))->assertOk();

    $artifactUrl = $response->json('task.researchArtifactUrl');
    expect($artifactUrl)->toStartWith('https://yak.example.com/artifacts/k3m9p2qa/research.html?')
        ->and(signedExpiryMinutes($artifactUrl))->toBe(30)
        ->and(str_replace('\\/', '/', $response->getContent()))->not->toContain('/viewer/');

    $entryUrl = collect($response->json('thread'))->flatMap(fn (array $entry) => $entry['links'] ?? [])->firstWhere('label', 'View research artifact')['url'] ?? null;
    expect($entryUrl)->toStartWith('https://yak.example.com/artifacts/k3m9p2qa/research.html?')
        ->and(signedExpiryMinutes($entryUrl))->toBe(30);

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

it('keeps the session links in the web thread', function () {
    $task = mediaTask($this->user, ['mode' => TaskMode::Review, 'context' => json_encode([
        'pr_number' => 42, 'body' => '![Screenshot](https://github.com/user-attachments/assets/' . MEDIA_PR_ASSET . ')',
    ])]);
    $fixTask = mediaTask($this->user, ['public_id' => 'n4q8r2xd']);
    $attachment = TaskAttachment::factory()->for($fixTask, 'task')->create(['original_name' => 'before.png']);

    foreach ([[$task, '/tasks/k3m9p2qa/pr-images/' . MEDIA_PR_ASSET], [$fixTask, "/task-attachments/{$attachment->id}"]] as [$shown, $link]) {
        $this->actingAs($this->user)->get(route('tasks.show', $shown))
            ->assertInertia(function ($page) use ($link) {
                $json = str_replace('\\/', '/', json_encode($page->toArray()['props']));
                expect($json)->toContain($link)->and($json)->not->toContain('app-media/');
            });
    }
});

describe('signed pull request images', function () {
    beforeEach(function () {
        config()->set('yak.channels.github.installation_id', 99999);
        GitHubInstallationToken::factory()->create(['installation_id' => 99999, 'token' => 'ghs_test_token', 'expires_at' => now()->addHour()]);
        $this->reviewTask = mediaTask($this->user, ['mode' => TaskMode::Review, 'context' => json_encode([
            'pr_number' => 42, 'body' => '![shot](https://github.com/user-attachments/assets/' . MEDIA_PR_ASSET . ')',
        ])]);
        $this->imageLink = AppMediaLinks::forPullRequestImage('k3m9p2qa', MEDIA_PR_ASSET);
    });

    it('serves a signed image without a session', function () {
        Http::fake([
            'api.github.com/markdown' => Http::response('<img src="https://private-user-images.githubusercontent.com/1/2-' . MEDIA_PR_ASSET . '.png?jwt=abc">'),
            'private-user-images.githubusercontent.com/*' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->get($this->imageLink)->assertOk()->assertHeader('Content-Type', 'image/png')->assertContent('png-bytes');
    });

    it('refuses an unsigned, changed or expired link', function () {
        Http::fake();

        $this->get('https://yak.example.com/app-media/tasks/k3m9p2qa/pr-images/' . MEDIA_PR_ASSET)->assertForbidden();
        $this->get(str_replace(MEDIA_PR_ASSET, '00000000-0000-0000-0000-000000000000', $this->imageLink))->assertForbidden();
        $this->travel(31)->minutes();
        $this->get($this->imageLink)->assertForbidden();
        Http::assertNothingSent();
    });

    it('answers 404 for an asset that is not in the stored PR body', function () {
        Http::fake();
        $link = AppMediaLinks::forPullRequestImage('k3m9p2qa', '00000000-0000-0000-0000-000000000000');

        $this->get($link)->assertNotFound();
        Http::assertNothingSent();
    });

    it('bounds every GitHub call and answers fast when GitHub is unreachable', function () {
        $recorded = [];
        Http::fake(function ($request, array $options) use (&$recorded) {
            $recorded[] = $options;

            throw new ConnectionException('timed out');
        });

        $this->get($this->imageLink)->assertStatus(503);

        expect($recorded)->not->toBeEmpty();
        foreach ($recorded as $options) {
            expect($options['timeout'])->toBe(3)->and($options['connect_timeout'])->toBe(2);
        }
    });

    it('bounds the image download too', function () {
        $recorded = [];
        Http::fake(function ($request, array $options) use (&$recorded) {
            $recorded[$request->url()] = $options;

            return str_contains($request->url(), 'api.github.com')
                ? Http::response('<img src="https://private-user-images.githubusercontent.com/1/2-' . MEDIA_PR_ASSET . '.png?jwt=abc">')
                : Http::response('png-bytes', 200, ['Content-Type' => 'image/png']);
        });

        $this->get($this->imageLink)->assertOk();

        expect($recorded)->toHaveCount(2);
        foreach ($recorded as $options) {
            expect($options['timeout'])->toBe(3)->and($options['connect_timeout'])->toBe(2);
        }
    });

    it('keeps the web route timeouts', function () {
        $recorded = [];
        Http::fake(function ($request, array $options) use (&$recorded) {
            $recorded[$request->url()] = $options;

            return str_contains($request->url(), 'api.github.com')
                ? Http::response('<img src="https://private-user-images.githubusercontent.com/1/2-' . MEDIA_PR_ASSET . '.png?jwt=abc">')
                : Http::response('png-bytes', 200, ['Content-Type' => 'image/png']);
        });

        $this->actingAs($this->user)->get(route('tasks.pr-images.show', [$this->reviewTask, MEDIA_PR_ASSET]))->assertOk();

        expect($recorded['https://api.github.com/markdown']['timeout'] ?? null)->not->toBe(3)
            ->and(collect($recorded)->last()['timeout'])->toBe(15);
    });
});
