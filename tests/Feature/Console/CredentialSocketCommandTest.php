<?php

it('fails without a configured uid', function () {
    config(['yak.threads.credential_socket_uid' => null]);

    $this->artisan('yak:credential-socket')->assertFailed();
});

it('fails without a configured path', function () {
    config(['yak.threads.credential_socket' => '', 'yak.threads.credential_socket_uid' => 1]);

    $this->artisan('yak:credential-socket')->assertFailed();
});

it('fails loudly when the socket directory is not safe', function () {
    $directory = sys_get_temp_dir() . '/yk-cmd-' . getmypid();
    @mkdir($directory, 0777);
    chmod($directory, 0777);
    config(['yak.threads.credential_socket' => $directory . '/redeem.sock', 'yak.threads.credential_socket_uid' => 1]);

    $this->artisan('yak:credential-socket')->expectsOutputToContain('is not safe')->assertFailed();

    rmdir($directory);
});
