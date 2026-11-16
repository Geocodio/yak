<?php

it('fixes the credential directory once as root and never touches the socket file', function () {
    $script = file_get_contents(base_path('docker/entrypoint.sh'));

    expect($script)->toContain('groupadd -g "$CRED_GID" yak-broker')
        ->toContain('usermod -aG "$CRED_GID" www-data')
        ->toContain('chown root:"$CRED_GID" "$CRED_DIR"')
        ->toContain('chmod 2770 "$CRED_DIR"')
        ->toContain('export YAK_THREADS_CREDENTIAL_SOCKET_DIRECTORY_GID')
        ->not->toMatch('/(chown|chmod)[^\n]*redeem\.sock/');
});
