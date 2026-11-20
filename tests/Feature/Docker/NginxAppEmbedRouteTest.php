<?php

it('sends the embedded pages to Laravel and every other /app/ path to the websocket server', function () {
    $config = file_get_contents(base_path('docker/nginx.conf'));

    preg_match('/location \/app\/embed\/ \{(.*?)\}/s', $config, $embed);
    preg_match('/location \/app\/ \{(.*?)\}/s', $config, $websocket);

    expect($embed[1] ?? '')->toContain('/index.php?$query_string')
        ->not->toContain('proxy_pass')
        ->and($websocket[1] ?? '')->toContain('proxy_pass http://127.0.0.1:8080');
});

it('keeps every embedded page route under the prefix the web server sends to Laravel', function () {
    $uris = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route) => $route->uri())
        ->filter(fn (string $uri) => str_starts_with($uri, 'app/'));

    expect($uris)->not->toBeEmpty()
        ->and($uris->reject(fn (string $uri) => str_starts_with($uri, 'app/embed/'))->values()->all())->toBe([]);
});
