<?php

use App\Http\Controllers\Api\App\Threads\TerminalSessionController;
use Illuminate\Support\Facades\Route;

// No `thread.drive` middleware: the origin check answers before the drive check.
Route::post('threads/{thread}/terminal-sessions', [TerminalSessionController::class, 'store'])->name('app.threads.terminal-sessions.store');
