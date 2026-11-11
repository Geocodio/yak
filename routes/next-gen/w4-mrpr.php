<?php

use App\Http\Controllers\Threads\PullRequests\AskToFixController;
use App\Http\Controllers\Threads\PullRequests\MergeOrderController;
use App\Http\Controllers\Threads\PullRequests\PullRequestsShowController;
use Illuminate\Support\Facades\Route;

Route::get('threads/{thread}/pull-requests', PullRequestsShowController::class)
    ->name('threads.pull-requests.show')
    ->where('thread', '[0-9]+');
Route::put('threads/{thread}/pull-requests/order', MergeOrderController::class)
    ->name('threads.pull-requests.order')
    ->where('thread', '[0-9]+');
Route::post('threads/{thread}/pull-requests/{repository}/fix', AskToFixController::class)
    ->name('threads.pull-requests.fix')
    ->where(['thread' => '[0-9]+', 'repository' => '[0-9]+']);
