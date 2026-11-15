<?php

use App\Http\Controllers\Api\App\Threads\TerminalSessionController;
use App\Http\Controllers\Api\App\Threads\ThreadFileController;
use Illuminate\Support\Facades\Route;

// No `thread.drive` middleware: the origin check answers before the drive check.
Route::post('threads/{thread}/terminal-sessions', [TerminalSessionController::class, 'store'])->name('app.threads.terminal-sessions.store');

Route::get('threads/{thread}/files', [ThreadFileController::class, 'tree'])->middleware('throttle:app-files')->name('app.threads.files.tree');
