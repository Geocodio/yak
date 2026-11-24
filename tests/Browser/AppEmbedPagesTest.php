<?php

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Thread;
use App\Models\YakTask;
use App\Support\AppEmbedRoutes;

/** Stands in for the app's script message handler; every message the page posts lands in `window.__sent`. */
function stubEmbedPageHandler(mixed $page): void
{
    $page->script('window.__sent = []; window.webkit = { messageHandlers: { yak: { postMessage: (message) => window.__sent.push(message) } } }; true');
}

it('shows the deployments pages without the app chrome and keeps the visit inside the embed', function () {
    [, , $headers] = embedPhone();
    $deployment = BranchDeployment::factory()->create(['status' => DeploymentStatus::Running, 'branch_name' => 'yak/retry-uploads']);
    $recorded = recordEmbedRequests();

    $page = visitEmbedPath('/app/embed/deployments', $headers);
    $page->assertPresent("[data-testid=\"deployment-row-{$deployment->public_id}\"]")
        ->assertMissing('[data-testid="app-shell"]')
        ->assertMissing('[data-testid="mobile-nav-toggle"]')
        ->assertMissing('[data-testid="thread-sidebar"]')
        ->assertNoJavaScriptErrors();

    $ready = settled($page, "(window.__yakEmbedLog ?? []).some((message) => message.type === 'ready')", "(window.__yakEmbedLog ?? []).find((message) => message.type === 'ready')");
    expect($ready)->toMatchArray(['v' => 1, 'type' => 'ready', 'url' => '/app/embed/deployments', 'title' => 'Deployments'])->and($ready)->not->toHaveKey('threadId');

    $page->click("[data-testid=\"deployment-row-{$deployment->public_id}\"] a[href^=\"/app/embed/deployments/\"]")
        ->assertScript('location.pathname', "/app/embed/deployments/{$deployment->public_id}")
        ->assertPresent('[data-testid="embed-toolbar"]')
        ->assertMissing('[data-testid="rebuild-deployment"]')
        ->assertMissing('[data-testid="destroy-deployment"]')
        ->assertNoJavaScriptErrors();

    collect($recorded())->each(function (array $request) {
        expect(in_array($request['route'], AppEmbedRoutes::ALLOWED, true) || $request['route'] === AppEmbedRoutes::BROADCAST_AUTH_URI || in_array($request['route'], ['login', 'app.web-session.store', 'app.embed.session'], true))
            ->toBeTrue("{$request['route']} is not on the embed allow-list")
            ->and($request['status'])->not->toBe(404);
    });
});

it('shows the PR review pages without the app chrome and posts link for a thread', function () {
    [$user, , $headers] = embedPhone();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id, 'title' => 'Retry failed uploads']);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'repo' => 'example/web.app', 'pr_url' => 'https://github.com/example/web.app/pull/17']);
    $review = PrReview::factory()->create(['repo' => 'example/web.app', 'pr_number' => 17, 'yak_task_id' => $task->id]);
    PrReviewComment::factory()->create(['pr_review_id' => $review->id]);

    $page = visitEmbedPath('/app/embed/pr-reviews', $headers);
    $page->assertPresent('[data-testid="pr-review-tabs"]')
        ->assertMissing('[data-testid="app-shell"]')
        ->assertMissing('[data-testid="mobile-nav-toggle"]')
        ->assertNoJavaScriptErrors();

    $page->click('[data-testid^="pr-review-comment-"] a')
        ->assertScript('location.pathname', '/app/embed/pr-reviews/example/web.app/17')
        ->assertPresent('[data-testid="review-subline"]')
        ->assertMissing('[data-testid="rerun-review"]')
        ->assertMissing('[data-testid="app-shell"]')
        ->assertNoJavaScriptErrors();
    stubEmbedPageHandler($page);

    $page->click('[data-testid="review-side-panel"] a[href*="/threads/"]');
    $sent = settled($page, "window.__sent.some((message) => message.type === 'link')", "window.__sent.filter((message) => message.type === 'link').map((message) => message.url)");

    expect($sent)->toHaveCount(1)->and($sent[0])->toEndWith("/threads/{$thread->public_id}");
    $page->assertScript('location.pathname', '/app/embed/pr-reviews/example/web.app/17');
});
