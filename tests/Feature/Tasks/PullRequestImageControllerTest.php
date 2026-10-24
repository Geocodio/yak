<?php

use App\Enums\TaskMode;
use App\Models\GitHubInstallationToken;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;

const PR_IMAGE_ASSET = 'f38ea4ee-97ce-48fc-a48e-a049c20f7cd4';

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    config()->set('yak.channels.github.installation_id', 99999);
    GitHubInstallationToken::factory()->create([
        'installation_id' => 99999,
        'token' => 'ghs_test_token',
        'expires_at' => now()->addHour(),
    ]);
    Repository::factory()->create(['slug' => 'acme/app', 'github_full_name' => 'acme/app']);

    $this->task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'repo' => 'acme/app',
        'context' => json_encode([
            'pr_number' => 42,
            'body' => '![shot](https://github.com/user-attachments/assets/' . PR_IMAGE_ASSET . ')',
        ]),
    ]);
});

test('streams the signed image from the rendered stored PR body', function () {
    $signedUrl = 'https://private-user-images.githubusercontent.com/1/2-' . PR_IMAGE_ASSET . '.png?jwt=abc&amp;x=1';

    Http::fake([
        'api.github.com/markdown' => Http::response('<p><img src="' . $signedUrl . '" alt="shot"></p>'),
        'private-user-images.githubusercontent.com/*' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    $this->get(route('tasks.pr-images.show', [$this->task, PR_IMAGE_ASSET]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertContent('png-bytes');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.github.com/markdown'
        && $request['context'] === 'acme/app'
        && str_contains($request['text'], PR_IMAGE_ASSET));
    Http::assertSent(fn ($request) => $request->url() === html_entity_decode($signedUrl));
});

test('returns 404 for an asset that is not in the stored PR body', function () {
    Http::fake();

    $this->get(route('tasks.pr-images.show', [$this->task, '00000000-0000-0000-0000-000000000000']))->assertNotFound();

    Http::assertNothingSent();
});

test('returns 404 when the rendered body has no such image', function () {
    Http::fake([
        'api.github.com/markdown' => Http::response('<p>No images.</p>'),
    ]);

    $this->get(route('tasks.pr-images.show', [$this->task, PR_IMAGE_ASSET]))->assertNotFound();
});

test('refuses a response that is not an image', function () {
    Http::fake([
        'api.github.com/markdown' => Http::response('<img src="https://private-user-images.githubusercontent.com/1/2-' . PR_IMAGE_ASSET . '.png?jwt=abc">'),
        'private-user-images.githubusercontent.com/*' => Http::response('<script>', 200, ['Content-Type' => 'text/html']),
    ]);

    $this->get(route('tasks.pr-images.show', [$this->task, PR_IMAGE_ASSET]))->assertNotFound();
});

test('returns 404 for a task that is not a review', function () {
    $task = YakTask::factory()->create(['mode' => TaskMode::Fix, 'repo' => 'acme/app']);

    $this->get(route('tasks.pr-images.show', [$task, PR_IMAGE_ASSET]))->assertNotFound();
});
