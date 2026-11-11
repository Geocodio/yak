<?php

use App\Http\Controllers\Threads\ThreadShareController;
use Illuminate\Support\Facades\Route;

Route::get('threads/{thread}/share', [ThreadShareController::class, 'show'])
    ->name('threads.share.show')
    ->where('thread', '[0-9]+');
Route::put('threads/{thread}/share', [ThreadShareController::class, 'update'])
    ->name('threads.share.update')
    ->where('thread', '[0-9]+');
