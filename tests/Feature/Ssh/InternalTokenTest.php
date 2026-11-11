<?php

use App\Http\Middleware\VerifySshInternalToken;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('yak.deployments.internal.ingress_ip_cidr', '0.0.0.0/0');
    config()->set('yak.ssh.internal_token', 'secret-token');
    Route::middleware(['restrict-to-ingress', 'ssh-internal-token'])->get('/internal/ssh-probe', fn () => response()->json(['ok' => true]));
});

it('refuses a request without a token', function () {
    $this->getJson('/internal/ssh-probe')->assertUnauthorized();
});

it('refuses a wrong token', function () {
    $this->withToken('nope')->getJson('/internal/ssh-probe')->assertUnauthorized();
});

it('refuses every token when none is configured', function () {
    config()->set('yak.ssh.internal_token', '');

    $this->withToken('')->getJson('/internal/ssh-probe')->assertUnauthorized();
    $this->withToken('secret-token')->getJson('/internal/ssh-probe')->assertUnauthorized();
});

it('lets the right token through', function () {
    $this->withToken('secret-token')->getJson('/internal/ssh-probe')->assertOk();
});

it('is registered under the ssh-internal-token alias', function () {
    // The HTTP kernel copies bootstrap/app.php aliases onto the router when it is resolved.
    app(Kernel::class);

    expect(app('router')->getMiddleware()['ssh-internal-token'])->toBe(VerifySshInternalToken::class);
});
