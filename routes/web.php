<?php

use App\Http\Controllers\ArtifactController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\LinearOAuthController;
use App\Livewire\CostDashboard;
use App\Livewire\Health;
use App\Livewire\PromptEditor;
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
    Route::get('auth/google', [GoogleAuthController::class, 'redirect'])->name('login');
    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
});

Route::middleware(['auth'])->group(function () {
    Route::livewire('tasks', TaskList::class)->name('tasks');
    Route::livewire('tasks/{task}', TaskDetail::class)->name('tasks.show');
    Route::livewire('costs', CostDashboard::class)->name('costs');
    Route::livewire('repos', RepoList::class)->name('repos');
    Route::livewire('repos/create', RepoForm::class)->name('repos.create');
    Route::livewire('repos/{repository}/edit', RepoForm::class)->name('repos.edit');
    Route::livewire('health', Health::class)->name('health');
    Route::livewire('prompts', PromptEditor::class)->name('prompts');
    Route::livewire('skills', Skills::class)->name('skills');

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

require __DIR__ . '/settings.php';
