<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * The CLI API error body: {error, message, details}.
 */
class CliError
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(string $error, string $message, int $status, array $details = []): JsonResponse
    {
        return response()->json(['error' => $error, 'message' => $message, 'details' => (object) $details], $status);
    }
}
