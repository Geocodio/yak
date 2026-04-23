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
        // Ignore X-Forwarded-For on purpose: this gate checks the direct
        // socket peer (the host-side reverse proxy forwarding via Docker
        // NAT). Laravel's trusted-proxies covers the Docker bridge, so
        // $request->ip() would return the end-user's public IP here.
        $ip = (string) ($request->server('REMOTE_ADDR') ?? '');

        if (! IpUtils::checkIp($ip, $cidr)) {
            abort(403, 'Internal endpoint; request not from ingress CIDR.');
        }

        return $next($request);
    }
}
