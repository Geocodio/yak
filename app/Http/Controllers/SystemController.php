<?php

namespace App\Http\Controllers;

use App\Http\Resources\AwakeShareData;
use App\Models\ClaudeAccount;
use Illuminate\Http\Request;
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

    /**
     * A section the package serves, then exactly one id. The page hands the
     * id to the package, which builds API request paths from it, so slashes,
     * dot segments and query characters are refused here rather than
     * steering the viewer's browser to another endpoint.
     */
    private const PATH_PATTERN = '#^(p|explore|entities|entity|traces|errors)/(?!\.\.?$)[A-Za-z0-9_.:-]+$#';

    public function __invoke(Request $request, ?string $path = null): Response
    {
        $path ??= self::DEFAULT_PATH;

        abort_unless(preg_match(self::PATH_PATTERN, $path) === 1, 404);

        return Inertia::render('System/Index', [
            'telemetryBase' => '/' . trim((string) config('telemetry-ui.path'), '/'),
            'path' => $path,
            'awakeSlots' => $request->user()?->can('create', [ClaudeAccount::class, true]) ? AwakeShareData::slots() : null,
        ]);
    }
}
