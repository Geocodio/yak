<?php

namespace App\Http\Controllers\Repositories;

use App\Http\Controllers\Controller;
use App\Http\Requests\Repositories\SaveManifestRequest;
use App\Models\Repository;
use Illuminate\Http\RedirectResponse;

class ManifestController extends Controller
{
    public function update(SaveManifestRequest $request, Repository $repository): RedirectResponse
    {
        $validated = $request->validated();

        $repository->update([
            'preview_manifest' => [
                'port' => $validated['port'],
                'health_probe_path' => $validated['health_probe_path'],
                'cold_start' => $validated['cold_start'] ?? '',
                'checkout_refresh' => $validated['checkout_refresh'] ?? '',
                'wake_timeout_seconds' => $validated['wake_timeout_seconds'] ?? 120,
            ],
        ]);

        return redirect()->route('repos.edit', $repository)->with('success', 'Preview manifest saved.');
    }
}
