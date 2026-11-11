<?php

namespace App\Http\Controllers\Api\Cli;

use App\Http\Controllers\Api\Cli\Concerns\ResolvesCliThread;
use App\Http\Controllers\Controller;
use App\Http\Responses\CliError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Placeholder until the ssh lookup ships. It still hides threads the caller may not see.
 */
class SshLookupController extends Controller
{
    use ResolvesCliThread;

    public function show(Request $request, int $thread): JsonResponse
    {
        $this->cliThread($request, $thread);

        return CliError::make('not_implemented', 'yak ssh is not available yet.', 501);
    }
}
