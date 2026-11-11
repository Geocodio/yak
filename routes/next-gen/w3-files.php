<?php

use App\Http\Controllers\Threads\ThreadFileController;
use Illuminate\Support\Facades\Route;

Route::get('threads/{thread}/files', [ThreadFileController::class, 'tree'])
    ->name('threads.files.tree')
    ->where('thread', '[0-9]+');
Route::get('threads/{thread}/files/content', [ThreadFileController::class, 'show'])
    ->name('threads.files.show')
    ->where('thread', '[0-9]+');
Route::put('threads/{thread}/files/content', [ThreadFileController::class, 'update'])
    ->name('threads.files.update')
    ->where('thread', '[0-9]+');
