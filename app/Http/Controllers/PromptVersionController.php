<?php

namespace App\Http\Controllers;

use App\Models\Prompt;
use App\Models\PromptVersion;
use App\Prompts\PromptDefinitions;
use Illuminate\Http\JsonResponse;

class PromptVersionController extends Controller
{
    public function show(string $slug, int $version): JsonResponse
    {
        abort_unless(PromptDefinitions::has($slug), 404);

        $prompt = Prompt::where('slug', $slug)->first();

        abort_if($prompt === null, 404);

        /** @var PromptVersion|null $promptVersion */
        $promptVersion = PromptVersion::where('id', $version)->where('prompt_id', $prompt->id)->first();

        abort_if($promptVersion === null, 404);

        return response()->json([
            'content' => $promptVersion->content,
            'version' => $promptVersion->version,
        ]);
    }
}
