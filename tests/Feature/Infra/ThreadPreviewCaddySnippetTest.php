<?php

beforeEach(function () {
    $this->snippet = file_get_contents(base_path('ansible/roles/ssl/templates/thread-preview.caddy.j2'));
    $this->caddyfile = file_get_contents(base_path('ansible/roles/ssl/templates/Caddyfile.j2'));
});

it('sets frame-ancestors to the dashboard only', function () {
    expect($this->snippet)
        ->toContain('header_down -X-Frame-Options')
        ->toContain('header_down Content-Security-Policy "frame-ancestors [^;]*;?" ""')
        ->toContain('header_down +Content-Security-Policy "frame-ancestors https://{{ yak_domain }}"');
});

it('strips every Yak cookie before the sandbox', function () {
    expect($this->snippet)->toContain('header_up Cookie "(__Host-yak_preview|yak_share_session|__Host-yak-session|yak-session|XSRF-TOKEN)=');
});

it('clears client X-Upstream headers before forward_auth', function () {
    $clear = strpos($this->snippet, 'request_header -X-Upstream-Host');

    expect($clear)->not->toBeFalse()
        ->and($clear)->toBeLessThan(strpos($this->snippet, 'forward_auth'))
        ->and($this->snippet)->toContain('request_header -X-Upstream-Port', 'request_header -X-Yak-Thread-Id');
});

it('routes the handshake and the status poll to the thread endpoints', function () {
    expect($this->snippet)
        ->toContain('handle /_yak/auth')
        ->toContain('rewrite * /internal/deployments/preview-auth')
        ->toContain('handle /_yak/preview-status')
        ->toContain('rewrite * /internal/threads/preview/status')
        ->toContain('uri /internal/threads/preview/wake');
});

it('is included before the generic preview handler', function () {
    $include = strpos($this->caddyfile, "{% include 'thread-preview.caddy.j2' %}");

    expect($include)->not->toBeFalse()
        ->and($include)->toBeLessThan(strpos($this->caddyfile, '@preview host'))
        ->and(substr_count($this->caddyfile, 'thread-preview'))->toBe(1);
});
