<?php

use App\Channels\Linear\OAuthService as LinearOAuthService;

it('requests app:assignable and app:mentionable scopes on the authorize URL', function () {
    config()->set('yak.channels.linear.oauth_client_id', 'cid');
    config()->set('yak.channels.linear.oauth_redirect_uri', 'https://example.test/auth/linear/callback');
    config()->set('yak.channels.linear.oauth_scopes', 'read,write,app:assignable,app:mentionable');

    $url = app(LinearOAuthService::class)->authorizeUrl('state-xyz');

    expect($url)->toContain('scope=read+write+app%3Aassignable+app%3Amentionable');
    expect($url)->toContain('actor=app');
    expect($url)->toContain('state=state-xyz');
});

it('still rejects the admin scope when combined with actor=app', function () {
    config()->set('yak.channels.linear.oauth_client_id', 'cid');
    config()->set('yak.channels.linear.oauth_redirect_uri', 'https://example.test/auth/linear/callback');
    config()->set('yak.channels.linear.oauth_scopes', 'read,write,admin');

    expect(fn () => app(LinearOAuthService::class)->authorizeUrl('state-xyz'))
        ->toThrow(RuntimeException::class, 'admin');
});
