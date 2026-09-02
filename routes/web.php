<?php

use App\Http\Controllers\ArtifactController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\LinearOAuthController;
use App\Http\Controllers\CostDashboardController;
use App\Http\Controllers\Deployments\AuthBounceController;
use App\Http\Controllers\Deployments\DeploymentActionController;
use App\Http\Controllers\Deployments\DeploymentController;
use App\Http\Controllers\Deployments\ShareLinkController;
use App\Http\Controllers\Internal\DeploymentStatusController;
use App\Http\Controllers\Internal\DeploymentWakeController;
use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\PromptController;
use App\Http\Controllers\PromptPreviewController;
use App\Http\Controllers\PromptVersionController;
use App\Http\Controllers\Repositories\GitHubCiDetectController;
use App\Http\Controllers\Repositories\GitHubRepoSearchController;
use App\Http\Controllers\Repositories\ManifestController;
use App\Http\Controllers\Repositories\RepositoryActionController;
use App\Http\Controllers\Repositories\RepositoryController;
use App\Http\Controllers\SkillController;
use App\Http\Controllers\Tasks\DismissSetupCardController;
use App\Http\Controllers\Tasks\RequestReReviewController;
use App\Http\Controllers\Tasks\StoreTaskController;
use App\Http\Controllers\Tasks\TaskActionController;
use App\Http\Controllers\Tasks\TaskController;
use App\Http\Controllers\Tasks\TaskListController;
use App\Http\Controllers\Tasks\TaskMessageController;
use App\Http\Controllers\VideoThemeAssetController;
use App\Livewire\Channels\ChannelList;
use App\Livewire\Health;
use App\Livewire\PrReviewFeedback;
use App\Livewire\PrReviewForPr;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('tasks');
    }

    return view('welcome');
})->name('home');

Route::get('letmein', function () {
    abort_unless(app()->environment('local'), 404);

    $user = User::query()->first() ?? User::factory()->create();
    auth()->login($user);

    return redirect()->route('tasks');
})->name('letmein');

Route::middleware('guest')->group(function () {
    Route::view('login', 'auth.login')->name('login');
    Route::get('auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google');
    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
});

Route::middleware(['auth'])->group(function () {
    Route::get('tasks', TaskListController::class)->name('tasks');
    Route::post('tasks', StoreTaskController::class)->name('tasks.store');
    Route::post('tasks/setup-card/dismiss', DismissSetupCardController::class)->name('tasks.setup-card.dismiss');
    Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
    Route::post('tasks/{task}/retry', [TaskActionController::class, 'retry'])->name('tasks.retry');
    Route::post('tasks/{task}/cancel', [TaskActionController::class, 'cancel'])->name('tasks.cancel');
    Route::post('tasks/{task}/rerun-review', [TaskActionController::class, 'rerunReview'])->name('tasks.rerun-review');
    Route::post('tasks/{task}/retry-render', [TaskActionController::class, 'retryRender'])->name('tasks.retry-render');
    Route::post('tasks/{task}/reroute', [TaskActionController::class, 'reroute'])->name('tasks.reroute');
    Route::post('tasks/{task}/messages', [TaskMessageController::class, 'store'])->name('tasks.messages.store');
    Route::post('tasks/{task}/re-request-review', RequestReReviewController::class)
        ->name('tasks.re-request-review');
    Route::get('costs', CostDashboardController::class)->name('costs');

    Route::get('repos', [RepositoryController::class, 'index'])->name('repos');
    Route::get('repos/create', [RepositoryController::class, 'create'])->name('repos.create');
    Route::post('repos', [RepositoryController::class, 'store'])->name('repos.store');
    Route::get('repos/github-search', GitHubRepoSearchController::class)->name('repos.github-search');
    Route::get('repos/github-detect', GitHubCiDetectController::class)->name('repos.github-detect');
    Route::get('repos/{repository}/edit', [RepositoryController::class, 'edit'])
        ->name('repos.edit')
        ->where('repository', '.+');
    Route::patch('repos/{repository}', [RepositoryController::class, 'update'])
        ->name('repos.update')
        ->where('repository', '.+');
    Route::delete('repos/{repository}', [RepositoryController::class, 'destroy'])
        ->name('repos.destroy')
        ->where('repository', '.+');
    Route::post('repos/{repository}/toggle-active', [RepositoryActionController::class, 'toggleActive'])
        ->name('repos.toggle-active')
        ->where('repository', '.+');
    Route::post('repos/{repository}/rerun-setup', [RepositoryActionController::class, 'rerunSetup'])
        ->name('repos.rerun-setup')
        ->where('repository', '.+');
    Route::post('repos/{repository}/review-open-prs', [RepositoryActionController::class, 'reviewOpenPrs'])
        ->name('repos.review-open-prs')
        ->where('repository', '.+');
    Route::post('repos/{repository}/rebuild-deployments', [RepositoryActionController::class, 'rebuildDeployments'])
        ->name('repos.rebuild-deployments')
        ->where('repository', '.+');
    Route::put('repos/{repository}/manifest', [ManifestController::class, 'update'])
        ->name('repos.manifest.update')
        ->where('repository', '.+');

    Route::livewire('health', Health::class)->name('health');
    Route::livewire('channels', ChannelList::class)->name('channels');

    Route::get('skills', [SkillController::class, 'index'])->name('skills');
    Route::post('skills', [SkillController::class, 'store'])->name('skills.install');
    Route::patch('skills/{name}', [SkillController::class, 'update'])
        ->name('skills.update')
        ->where('name', '.+');
    Route::post('skills/{name}/update', [SkillController::class, 'upgrade'])
        ->name('skills.upgrade')
        ->where('name', '.+');
    Route::delete('skills/{name}', [SkillController::class, 'destroy'])
        ->name('skills.destroy')
        ->where('name', '.+');

    Route::post('marketplaces', [MarketplaceController::class, 'store'])->name('marketplaces.store');
    Route::post('marketplaces/refresh', [MarketplaceController::class, 'refresh'])->name('marketplaces.refresh');
    Route::delete('marketplaces/{name}', [MarketplaceController::class, 'destroy'])
        ->name('marketplaces.destroy')
        ->where('name', '.+');

    Route::get('prompts', [PromptController::class, 'index'])->name('prompts');
    Route::get('prompts/{slug}', [PromptController::class, 'show'])->name('prompts.show');
    Route::put('prompts/{slug}', [PromptController::class, 'update'])->name('prompts.update');
    Route::delete('prompts/{slug}', [PromptController::class, 'reset'])->name('prompts.reset');
    Route::post('prompts/{slug}/preview', PromptPreviewController::class)->name('prompts.preview');
    Route::get('prompts/{slug}/versions/{version}', [PromptVersionController::class, 'show'])
        ->name('prompts.versions.show')
        ->where('version', '[0-9]+');
    Route::livewire('pr-reviews', PrReviewFeedback::class)->name('pr-reviews');
    Route::livewire('pr-reviews/for/{repoSlug}/{prNumber}', PrReviewForPr::class)
        ->name('pr-reviews.for-pr')
        ->where('repoSlug', '.+')
        ->where('prNumber', '[0-9]+');

    Route::get('deployments', [DeploymentController::class, 'index'])->name('deployments');
    Route::get('deployments/{deployment}', [DeploymentController::class, 'show'])
        ->name('deployments.show')
        ->where('deployment', '[0-9]+');
    Route::patch('deployments/{deployment}/hibernation', [DeploymentActionController::class, 'updateHibernation'])
        ->name('deployments.hibernation.update')
        ->where('deployment', '[0-9]+');
    Route::post('deployments/{deployment}/rebuild', [DeploymentActionController::class, 'rebuild'])
        ->name('deployments.rebuild')
        ->where('deployment', '[0-9]+');
    Route::delete('deployments/{deployment}', [DeploymentActionController::class, 'destroy'])
        ->name('deployments.destroy')
        ->where('deployment', '[0-9]+');
    Route::post('deployments/{deployment}/share', [ShareLinkController::class, 'store'])
        ->name('deployments.share.store')
        ->where('deployment', '[0-9]+');
    Route::delete('deployments/{deployment}/share', [ShareLinkController::class, 'destroy'])
        ->name('deployments.share.destroy')
        ->where('deployment', '[0-9]+');

    Route::get('auth/linear', [LinearOAuthController::class, 'redirect'])->name('auth.linear.redirect');
    Route::get('auth/linear/callback', [LinearOAuthController::class, 'callback'])->name('auth.linear.callback');

    // Temporary route to prove the Inertia boot; removed once real pages land.
    Route::get('inertia-boot', fn () => Inertia::render('Placeholder', ['label' => 'boot']))
        ->name('inertia-boot');
});

Route::get('artifacts/public/{token}', [ArtifactController::class, 'publicImage'])
    ->name('artifacts.public')
    ->where('token', '[0-9A-Za-z]{26}');

Route::get('video-theme/logo', [VideoThemeAssetController::class, 'logo'])
    ->name('video-theme.logo');

Route::get('video-theme/sample', [VideoThemeAssetController::class, 'sample'])
    ->middleware('auth')
    ->name('video-theme.sample');

Route::get('artifacts/{task}/viewer/{filename}', [ArtifactController::class, 'viewer'])
    ->name('artifacts.viewer')
    ->middleware('auth')
    ->where('filename', '.*');

Route::get('artifacts/{task}/{filename}', [ArtifactController::class, 'show'])
    ->name('artifacts.show')
    ->where('filename', '.*');

// Wake allows anonymous access with a valid share token OR cookie;
// otherwise falls through to requiring an authenticated session.
Route::middleware(['restrict-to-ingress'])
    ->get('/internal/deployments/wake', DeploymentWakeController::class)
    ->name('deployments.wake');

// Public bounce endpoint on the dashboard. The wake endpoint redirects
// unauthenticated preview visits here; this controller either forwards
// already-authed users back to the preview or kicks off the OAuth flow
// with the preview URL stored as url.intended. Signature prevents an
// attacker from crafting standalone phishing links to the endpoint —
// only the wake endpoint (which validates the `to` hostname against
// an existing deployment) can mint a valid URL.
Route::get('/deployments/auth-bounce', AuthBounceController::class)
    ->middleware('signed')
    ->name('deployments.auth-bounce');

// Status is used by the shim JS on an already-trusted page; keep `auth`.
Route::middleware(['restrict-to-ingress', 'auth'])
    ->get('/internal/deployments/status', DeploymentStatusController::class)
    ->name('deployments.status');

require __DIR__ . '/settings.php';
