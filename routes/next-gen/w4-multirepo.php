<?php

use App\Http\Controllers\Threads\RepositoryAccessController;
use App\Http\Controllers\Threads\ThreadRepositoryProgressController;
use Illuminate\Support\Facades\Route;

Route::get('threads/new/repository-access', RepositoryAccessController::class)
    ->name('threads.repository-access');

Route::get('threads/{thread}/repositories', ThreadRepositoryProgressController::class)
    ->name('threads.repositories.progress')
    ->where('thread', '[0-9]+');
