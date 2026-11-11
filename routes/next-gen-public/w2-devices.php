<?php

use App\Http\Controllers\Cli\DeviceCodeController;
use App\Http\Controllers\Cli\DeviceTokenController;
use App\Http\Middleware\CliProtocol;
use Illuminate\Support\Facades\Route;

Route::prefix('api/cli/v1')->middleware('cli.protocol')->withoutMiddleware(CliProtocol::WITHOUT_WEB_STATE)->group(function (): void {
    Route::post('device/code', DeviceCodeController::class)->middleware('throttle:cli-device-code')->name('cli.device.code');
    Route::post('device/token', DeviceTokenController::class)->name('cli.device.token');

    Route::middleware('auth.device')->group(function (): void {
        // Authenticated device routes are added below.
    });
});
