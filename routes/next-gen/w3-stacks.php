<?php

use App\Http\Controllers\Threads\Stack\ChildDiffController;
use App\Http\Controllers\Threads\Stack\ChildOverrideController;
use App\Http\Controllers\Threads\Stack\StackChildStoreController;
use App\Http\Controllers\Threads\Stack\StackLimitController;
use App\Http\Controllers\Threads\Stack\StackShowController;
use App\Http\Controllers\Threads\Stack\StackStopController;
use Illuminate\Support\Facades\Route;

Route::post('threads/{thread}/stack/take-over', [ChildOverrideController::class, 'takeOver'])
    ->name('threads.stack.take-over')
    ->where('thread', '[0-9]+');
Route::post('threads/{thread}/stack/split', [ChildOverrideController::class, 'split'])
    ->name('threads.stack.split')
    ->where('thread', '[0-9]+');
Route::post('threads/{thread}/stack/drop', [ChildOverrideController::class, 'drop'])
    ->name('threads.stack.drop')
    ->where('thread', '[0-9]+');

Route::patch('threads/{thread}/stack/limit', StackLimitController::class)
    ->name('threads.stack.limit')
    ->where('thread', '[0-9]+');

Route::post('threads/{thread}/stack/stop', StackStopController::class)
    ->name('threads.stack.stop')
    ->where('thread', '[0-9]+');

Route::get('threads/{thread}/stack', StackShowController::class)
    ->name('threads.stack.show')
    ->where('thread', '[0-9]+');
Route::post('threads/{thread}/stack/children', StackChildStoreController::class)
    ->name('threads.stack.children.store')
    ->where('thread', '[0-9]+');
Route::get('threads/{thread}/stack/children/{child}/diff', ChildDiffController::class)
    ->name('threads.stack.children.diff')
    ->where(['thread' => '[0-9]+', 'child' => '[0-9]+']);
