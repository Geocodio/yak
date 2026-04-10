<?php

use App\Http\Controllers\ArtifactController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Livewire\Tasks\TaskDetail;
use App\Livewire\Tasks\TaskList;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('auth/google', [GoogleAuthController::class, 'redirect'])->name('login');
    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
});

Route::middleware(['auth'])->group(function () {
    Route::livewire('tasks', TaskList::class)->name('tasks');
    Route::livewire('tasks/{task}', TaskDetail::class)->name('tasks.show');
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

Route::get('artifacts/{task}/viewer/{filename}', [ArtifactController::class, 'viewer'])
    ->name('artifacts.viewer')
    ->middleware('auth')
    ->where('filename', '.*');

Route::get('artifacts/{task}/{filename}', [ArtifactController::class, 'show'])
    ->name('artifacts.show')
    ->where('filename', '.*');

require __DIR__.'/settings.php';
