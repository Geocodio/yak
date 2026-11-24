<?php

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Models\Device;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\User;
use App\Support\AppEmbedRoutes;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => $this->withoutVite());

/**
 * @return array{user: User, device: Device, session: array<string, mixed>, deployment: BranchDeployment}
 */
function embedPagesWorld(): array
{
    [$user, $device] = embedPhone();
    $deployment = BranchDeployment::factory()->create(['status' => DeploymentStatus::Running, 'branch_name' => 'yak/retry-uploads']);
    $review = PrReview::factory()->create(['repo' => 'example/web.app', 'pr_number' => 17]);
    PrReviewComment::factory()->create(['pr_review_id' => $review->id]);

    return ['user' => $user, 'device' => $device, 'session' => embedSessionData($user, $device), 'deployment' => $deployment];
}

it('renders each page for an embed session without the drawer data', function (string $uri, string $component) {
    ['user' => $user, 'deployment' => $deployment, 'session' => $session] = embedPagesWorld();

    actingAsEmbed($session);
    $this->get(str_replace('{deployment}', $deployment->public_id, $uri))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component($component)
            ->where('embed', ['bridge' => 1])
            ->where('auth.user.id', $user->id)
            ->where('threadList', null)
            ->where('operationsPanel', null));
})->with([
    'deployments' => ['/app/embed/deployments', 'Deployments/Index'],
    'deployment' => ['/app/embed/deployments/{deployment}', 'Deployments/Show'],
    'pr reviews' => ['/app/embed/pr-reviews', 'PrReviews/Index'],
    'pr review' => ['/app/embed/pr-reviews/example/web.app/17', 'PrReviews/ForPr'],
]);

it('answers an embed session with the same props as the web page', function (string $embedUri, string $webUri) {
    ['user' => $user, 'deployment' => $deployment, 'session' => $session] = embedPagesWorld();
    $embedUri = str_replace('{deployment}', $deployment->public_id, $embedUri);
    $webUri = str_replace('{deployment}', $deployment->public_id, $webUri);

    actingAsEmbed($session);
    $embed = $this->get($embedUri)->assertOk()->viewData('page')['props'];

    app('auth')->forgetGuards();
    $this->flushSession()->flushHeaders();
    $web = $this->actingAs($user)->get($webUri)->assertOk()->viewData('page')['props'];

    // A paginated prop carries its own address, which differs by design; its rows must not.
    foreach (['deployments.data', 'deployment', 'filters', 'comments.data', 'stats', 'pr', 'runs', 'logs', 'hibernation'] as $key) {
        expect(data_get($embed, $key))->toEqual(data_get($web, $key));
    }
})->with([
    'deployments' => ['/app/embed/deployments', '/deployments'],
    'deployment' => ['/app/embed/deployments/{deployment}', '/deployments/{deployment}'],
    'pr reviews' => ['/app/embed/pr-reviews', '/pr-reviews'],
    'pr review' => ['/app/embed/pr-reviews/example/web.app/17', '/pr-reviews/for/example/web.app/17'],
]);

it('serves the polling, partial and scroll visits of each page on its own address', function () {
    ['deployment' => $deployment, 'session' => $session] = embedPagesWorld();

    foreach ([
        ['/app/embed/deployments', 'Deployments/Index', 'deployments'],
        ["/app/embed/deployments/{$deployment->public_id}", 'Deployments/Show', 'logs'],
        ['/app/embed/pr-reviews/example/web.app/17', 'PrReviews/ForPr', 'runs'],
    ] as [$uri, $component, $only]) {
        actingAsEmbed($session);
        $version = $this->get($uri)->assertOk()->viewData('page')['version'];
        actingAsEmbed($session);
        $this->get($uri, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => $component, 'X-Inertia-Partial-Data' => $only])
            ->assertOk()->assertHeader('X-Inertia', 'true');
    }
});

it('answers 401 without a session and to a normal web session', function (string $uri) {
    ['user' => $user, 'deployment' => $deployment] = embedPagesWorld();
    $uri = str_replace('{deployment}', $deployment->public_id, $uri);

    $this->get($uri)->assertUnauthorized();
    $this->actingAs($user)->get($uri)->assertUnauthorized();
})->with([
    '/app/embed/deployments',
    '/app/embed/deployments/{deployment}',
    '/app/embed/pr-reviews',
    '/app/embed/pr-reviews/example/web.app/17',
]);

it('answers an unknown deployment like the web does and shows a deployment to every phone user', function () {
    ['user' => $user, 'deployment' => $deployment, 'session' => $session] = embedPagesWorld();
    [, $otherDevice] = embedPhone();

    actingAsEmbed($session);
    $this->get('/app/embed/deployments/zzzzzzzz')->assertNotFound();
    app('auth')->forgetGuards();
    $this->flushSession()->flushHeaders();
    $this->actingAs($user)->get('/deployments/zzzzzzzz')->assertNotFound();

    // The web has no per-person rule for these pages, so none is added for the embed.
    app('auth')->forgetGuards();
    $this->flushSession()->flushHeaders();
    actingAsEmbed(embedSessionData($otherDevice->user, $otherDevice));
    $this->get("/app/embed/deployments/{$deployment->public_id}")->assertOk();
});

it('lists no write route of the pages on the allow-list', function () {
    $writes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route): bool => str_starts_with($route->uri(), 'deployments/') || str_starts_with($route->uri(), 'pr-reviews/') || $route->getName() === 'repos.manifest.update')
        ->reject(fn (LaravelRoute $route): bool => in_array('GET', $route->methods(), true))
        ->map(fn (LaravelRoute $route): string => (string) $route->getName())
        ->all();

    expect($writes)->toContain('deployments.rebuild', 'deployments.destroy', 'deployments.hibernation.update', 'deployments.share.store', 'deployments.share.destroy', 'pr-reviews.for-pr.rerun', 'pr-reviews.for-pr.runs.rerun')
        ->and(array_values(array_intersect($writes, AppEmbedRoutes::ALLOWED)))->toBe([]);
});

it('answers 404 to every write action of the pages', function (string $method, string $uri) {
    ['deployment' => $deployment, 'session' => $session] = embedPagesWorld();

    actingAsEmbed($session);
    $this->call($method, str_replace('{deployment}', $deployment->public_id, $uri))->assertNotFound();
})->with([
    ['PATCH', '/deployments/{deployment}/hibernation'],
    ['POST', '/deployments/{deployment}/rebuild'],
    ['DELETE', '/deployments/{deployment}'],
    ['POST', '/deployments/{deployment}/share'],
    ['DELETE', '/deployments/{deployment}/share'],
    ['PUT', '/repos/example/web/manifest'],
    ['POST', '/pr-reviews/for/example/web.app/17/rerun'],
    ['POST', '/pr-reviews/for/example/web.app/17/runs/aaaaaaaa/rerun'],
    ['GET', '/deployments'],
    ['GET', '/pr-reviews'],
    ['GET', '/pr-reviews/for/example/web.app/17'],
]);

it('keeps the web pages themselves off the allow-list', function () {
    expect(AppEmbedRoutes::ALLOWED)->not->toContain('deployments', 'deployments.show', 'pr-reviews', 'pr-reviews.for-pr');
});

it('redeems a code bound to each page and refuses one bound to a near miss', function (string $path, bool $isBound) {
    [, , $headers] = embedPhone();

    $response = test()->postJson('/api/app/v1/web-session', ['path' => $path], $headers);

    if (! $isBound) {
        $response->assertStatus(422);

        return;
    }

    $code = (string) $response->assertCreated()->json('code');
    app('auth')->forgetGuards();
    test()->flushHeaders()->withHeaders(['User-Agent' => EMBED_USER_AGENT])->post('http://localhost/app/embed/session', ['code' => $code])
        ->assertStatus(303)->assertRedirect("http://localhost{$path}");
})->with([
    'deployments' => ['/app/embed/deployments', true],
    'deployment' => ['/app/embed/deployments/abcd1234', true],
    'pr reviews' => ['/app/embed/pr-reviews', true],
    'pr review' => ['/app/embed/pr-reviews/example/web.app/17', true],
    'deployments with a query' => ['/app/embed/deployments?status=all', false],
    'deployment with a short id' => ['/app/embed/deployments/abcd123', false],
    'pr review with a trailing path' => ['/app/embed/pr-reviews/example/web.app/17/log', false],
    'pr reviews with a fragment' => ['/app/embed/pr-reviews#x', false],
]);
