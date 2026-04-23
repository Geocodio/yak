<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class RestrictToIngress
{
    public function handle(Request $request, Closure $next): Response
    {
        $cidr = (string) config('yak.deployments.internal.ingress_ip_cidr');
        $ip = $request->ip();

        if (! IpUtils::checkIp($ip, $cidr)) {
            abort(403, 'Internal endpoint; request not from ingress CIDR.');
        }

        return $next($request);
    }
}
