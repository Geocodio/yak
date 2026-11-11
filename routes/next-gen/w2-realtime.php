<?php

use App\Http\Controllers\Threads\ThreadEventController;
use Illuminate\Support\Facades\Route;

Route::get('threads/{thread}/events', [ThreadEventController::class, 'index'])
    ->name('threads.events.index')
    ->where('thread', '[0-9]+');
Route::get('threads/{thread}/events/{sequence}', [ThreadEventController::class, 'show'])
    ->name('threads.events.show')
    ->where(['thread' => '[0-9]+', 'sequence' => '[0-9]+']);
