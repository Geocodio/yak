<?php

use App\Http\Controllers\Api\App\WebSessionController;
use Illuminate\Support\Facades\Route;

// The login hand-off for the embedded web view: mints a code that POST /app/embed/session redeems.
Route::post('web-session', WebSessionController::class)->middleware('throttle:app-web-session')->name('app.web-session.store');
