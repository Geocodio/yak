<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * The System section: cboxdk/laravel-telemetry-ui's own pages inside Yak's
 * layout. `/system/{path}` mirrors the package's paths (`p/queues`,
 * `explore/traces`, `traces/{id}` ...), so every link the package builds
 * lands on a Yak URL. The panels fetch their data from the package's API
 * under `telemetryBase`, which the `viewTelemetryUi` gate protects.
 */
class SystemController extends Controller
{
    /** The page `/system` opens on: host resources, queues are a click away. */
    private const DEFAULT_PATH = 'p/system';

    /** The first path segments the package serves; anything else is a 404. */
    private const PACKAGE_SECTIONS = ['p', 'explore', 'entities', 'entity', 'traces', 'errors'];

    public function __invoke(?string $path = null): Response
    {
        $path = trim((string) $path, '/');

        if ($path === '') {
            $path = self::DEFAULT_PATH;
        }

        abort_unless(in_array(explode('/', $path)[0], self::PACKAGE_SECTIONS, true), 404);

        return Inertia::render('System/Index', [
            'telemetryBase' => '/' . trim((string) config('telemetry-ui.path'), '/'),
            'path' => $path,
        ]);
    }
}
