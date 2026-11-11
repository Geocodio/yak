<?php

use App\Http\Controllers\Threads\RepositoryAccessController;
use App\Http\Controllers\Threads\ThreadRepositoryController;
use App\Http\Controllers\Threads\ThreadRepositoryProgressController;
use Illuminate\Support\Facades\Route;

Route::get('threads/new/repository-access', RepositoryAccessController::class)
    ->middleware('throttle:30,1')
    ->name('threads.repository-access');

Route::get('threads/{thread}/repositories', ThreadRepositoryProgressController::class)
    ->name('threads.repositories.progress')
    ->where('thread', '[0-9]+');

Route::patch('threads/{thread}/repositories/{repository}', [ThreadRepositoryController::class, 'update'])
    ->name('threads.repositories.update')
    ->where(['thread' => '[0-9]+', 'repository' => '[0-9]+'])
    ->scopeBindings();

Route::post('threads/{thread}/repositories/{repository}/retry', [ThreadRepositoryController::class, 'retry'])
    ->name('threads.repositories.retry')
    ->where(['thread' => '[0-9]+', 'repository' => '[0-9]+'])
    ->scopeBindings();
