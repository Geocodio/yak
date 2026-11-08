<?php

it('refuses to start without the socket path and the broker uid', function () {
    config()->set('yak.credential_socket', ['path' => '', 'allowed_uid' => null]);

    $this->artisan('yak:credential-socket')->assertFailed();
});
