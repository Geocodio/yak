<?php

use App\Enums\PushTokenKind;
use App\Http\Controllers\Api\App\Push\LiveActivityChannelController;
use App\Http\Controllers\Api\App\Push\LiveActivityController;
use App\Http\Controllers\Api\App\Push\NotificationPreferenceController;
use App\Http\Controllers\Api\App\Push\PushTestController;
use App\Http\Controllers\Api\App\Push\PushTokenController;
use Illuminate\Support\Facades\Route;

Route::put('push-tokens/{kind}', [PushTokenController::class, 'update'])->where('kind', PushTokenKind::routePattern())->name('app.push-tokens.update');
Route::delete('push-tokens/{kind}', [PushTokenController::class, 'destroy'])->where('kind', PushTokenKind::routePattern())->name('app.push-tokens.destroy');
Route::get('notifications', [NotificationPreferenceController::class, 'show'])->name('app.notifications.show');
Route::put('notifications', [NotificationPreferenceController::class, 'update'])->name('app.notifications.update');
Route::put('live-activities', [LiveActivityController::class, 'update'])->name('app.live-activities.update');
Route::post('push-tokens/alert/test', PushTestController::class)->middleware('throttle:app-push-test')->name('app.push-tokens.test');
Route::post('threads/{thread}/live-activity-channel', LiveActivityChannelController::class)->name('app.threads.live-activity-channel');
