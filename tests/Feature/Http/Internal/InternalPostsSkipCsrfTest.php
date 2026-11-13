<?php

beforeEach(function () {
    // CSRF checks are skipped while the app runs as "testing"; pretend otherwise.
    $this->app['env'] = 'local';
    config()->set('yak.deployments.internal.ingress_ip_cidr', '0.0.0.0/0');
});

it('does not demand a CSRF token on token-authenticated internal POSTs', function () {
    $this->postJson('/internal/ssh/terminal/redeem', [])->assertStatus(401);
});

it('still demands a CSRF token on ordinary web POSTs', function () {
    $this->postJson('/threads', [])->assertStatus(419);
});
