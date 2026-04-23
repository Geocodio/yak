<?php

use App\Http\Controllers\ArtifactController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\LinearOAuthController;
use App\Http\Controllers\Internal\DeploymentStatusController;
use App\Http\Controllers\Internal\DeploymentWakeController;
use App\Livewire\Channels\ChannelList;
use App\Livewire\CostDashboard;
use App\Livewire\Deployments\DeploymentIndex;
use App\Livewire\Deployments\DeploymentShow;
use App\Livewire\Health;
use App\Livewire\PromptEditor;
use App\Livewire\PrReviewFeedback;
use App\Livewire\PrReviewForPr;
use App\Livewire\Repos\RepoForm;
use App\Livewire\Repos\RepoList;
use App\Livewire\Skills;
use App\Livewire\Tasks\TaskDetail;
use App\Livewire\Tasks\TaskList;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('tasks');
    }

    return view('welcome');
})->name('home');

Route::middleware('guest')->group(function () {
    Route::view('login', 'auth.login')->name('login');
    Route::get('auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google');
    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
});

Route::middleware(['auth'])->group(function () {
    Route::livewire('tasks', TaskList::class)->name('tasks');
    Route::livewire('tasks/{task}', TaskDetail::class)->name('tasks.show');
    Route::livewire('costs', CostDashboard::class)->name('costs');
    Route::livewire('repos', RepoList::class)->name('repos');
    Route::livewire('repos/create', RepoForm::class)->name('repos.create');
    Route::livewire('repos/{repository}/edit', RepoForm::class)
        ->name('repos.edit')
        ->where('repository', '.+');
    Route::livewire('health', Health::class)->name('health');
    Route::livewire('channels', ChannelList::class)->name('channels');
    Route::livewire('prompts', PromptEditor::class)->name('prompts');
    Route::livewire('skills', Skills::class)->name('skills');
    Route::livewire('pr-reviews', PrReviewFeedback::class)->name('pr-reviews');
    Route::livewire('pr-reviews/for/{repoSlug}/{prNumber}', PrReviewForPr::class)
        ->name('pr-reviews.for-pr')
        ->where('repoSlug', '.+')
        ->where('prNumber', '[0-9]+');

    Route::livewire('deployments', DeploymentIndex::class)->name('deployments');
    Route::livewire('deployments/{deployment}', DeploymentShow::class)->name('deployments.show');

    Route::get('auth/linear', [LinearOAuthController::class, 'redirect'])->name('auth.linear.redirect');
    Route::get('auth/linear/callback', [LinearOAuthController::class, 'callback'])->name('auth.linear.callback');
});

Route::get('artifacts/{task}/viewer/{filename}', [ArtifactController::class, 'viewer'])
    ->name('artifacts.viewer')
    ->middleware('auth')
    ->where('filename', '.*');

Route::get('artifacts/{task}/{filename}', [ArtifactController::class, 'show'])
    ->name('artifacts.show')
    ->where('filename', '.*');

Route::prefix('internal/deployments')
    ->middleware(['restrict-to-ingress', 'auth'])
    ->group(function () {
        Route::get('/wake', DeploymentWakeController::class)->name('deployments.wake');
        Route::get('/status', DeploymentStatusController::class)->name('deployments.status');
    });

require __DIR__ . '/settings.php';
