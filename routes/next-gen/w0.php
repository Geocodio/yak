<?php

use App\Http\Controllers\Threads\ThreadController;
use Illuminate\Support\Facades\Route;

Route::get('threads/{thread}', [ThreadController::class, 'show'])
    ->name('threads.show')
    ->where('thread', '[0-9]+');
