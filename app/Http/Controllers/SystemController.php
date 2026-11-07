<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * The System page: host resources, the sandbox pool and queue health,
 * drawn by cboxdk/laravel-telemetry-ui panels embedded in Yak's layout.
 * The panels fetch their own data from the package's API under
 * `telemetryBase`, which the `viewTelemetryUi` gate protects.
 */
class SystemController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('System/Index', [
            'telemetryBase' => '/' . trim((string) config('telemetry-ui.path'), '/'),
        ]);
    }
}
