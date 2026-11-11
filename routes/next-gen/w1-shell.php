<?php

use App\Http\Controllers\Threads\ThreadListController;
use App\Http\Controllers\Threads\ThreadSeenController;
use Illuminate\Support\Facades\Route;

Route::get('threads/list', ThreadListController::class)->name('threads.list');
Route::post('threads/{thread}/seen', ThreadSeenController::class)
    ->name('threads.seen')
    ->where('thread', '[0-9]+');
