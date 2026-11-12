<?php

it('runs the credential socket program as www-data', function () {
    $config = file_get_contents(base_path('docker/supervisord.conf'));

    expect($config)->toContain('[program:yak-credential-socket]');

    preg_match('/\[program:yak-credential-socket\](.*?)(?=\n\[|\z)/s', $config, $block);

    expect($block[1])->toContain('command=php /app/artisan yak:credential-socket')
        ->toContain('autorestart=true')
        ->toContain('user=www-data');
});
