<?php

it('fails without a configured uid', function () {
    config(['yak.threads.credential_socket_uid' => null]);

    $this->artisan('yak:credential-socket')->assertFailed();
});

it('fails without a configured path', function () {
    config(['yak.threads.credential_socket' => '', 'yak.threads.credential_socket_uid' => 1]);

    $this->artisan('yak:credential-socket')->assertFailed();
});
