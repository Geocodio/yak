<?php

namespace App\Http\Middleware;

use App\Support\ClientApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps the server's protocol range on every CLI and app API response, including the ones no route produced (an unknown path, a wrong method) and the ones that fail.
 */
class StampClientProtocol
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $api = ClientApi::for($request);

        if ($api !== null) {
            $response->headers->set('X-Yak-Protocol', (string) config("yak.{$api}.protocol"));
            $response->headers->set('X-Yak-Min-Protocol', (string) config("yak.{$api}.min_protocol"));
        }

        return $response;
    }
}
