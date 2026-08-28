<?php

namespace App\Livewire\Tasks\Support;

use App\Models\Artifact;
use Illuminate\Support\Facades\Route;

/**
 * Poster and preview-GIF images are embedded in long-lived surfaces (PR
 * bodies, the task list), so they resolve through the permanent public
 * token route when the artifact carries one and fall back to the 7-day
 * signed URL otherwise.
 */
final class ArtifactPreviewUrl
{
    public static function for(Artifact $artifact): string
    {
        $token = $artifact->public_token ?? null;

        if (is_string($token) && $token !== '' && Route::has('artifacts.public')) {
            return route('artifacts.public', ['token' => $token]);
        }

        return $artifact->signedUrl();
    }
}
