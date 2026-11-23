<?php

use App\Http\Controllers\Api\App\SearchController;
use App\Http\Controllers\Api\App\Threads\ThreadManagementController;
use Illuminate\Support\Facades\Route;

Route::post('threads/{thread}/star', [ThreadManagementController::class, 'star'])->name('app.threads.star');
Route::post('threads/{thread}/close', [ThreadManagementController::class, 'close'])->middleware('thread.drive')->name('app.threads.close');
Route::delete('threads/{thread}', [ThreadManagementController::class, 'destroy'])->middleware('thread.drive')->name('app.threads.destroy');
Route::get('search', SearchController::class)->middleware('throttle:30,1')->name('app.search');
