<?php

use App\Http\Controllers\Cli\DeviceCodeController;
use App\Http\Controllers\Cli\DeviceController;
use App\Http\Controllers\Cli\DeviceTokenController;
use App\Http\Controllers\Cli\MeController;
use App\Http\Controllers\Cli\RepositoryResolveController;
use App\Http\Middleware\CliProtocol;
use Illuminate\Support\Facades\Route;

Route::prefix('api/cli/v1')->middleware('cli.protocol')->withoutMiddleware(CliProtocol::WITHOUT_WEB_STATE)->group(function (): void {
    Route::post('device/code', DeviceCodeController::class)->middleware('throttle:cli-device-code')->name('cli.device.code');
    Route::post('device/token', DeviceTokenController::class)->name('cli.device.token');

    Route::middleware('auth.device')->group(function (): void {
        Route::get('me', MeController::class)->name('cli.me');
        Route::delete('device', [DeviceController::class, 'destroy'])->name('cli.device.destroy');
        Route::post('repos/resolve', RepositoryResolveController::class)->name('cli.repos.resolve');
    });
});
