<?php

use App\Http\Middleware\ClientProtocol;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

beforeEach(function () {
    Route::middleware(['web', 'client.protocol:app'])->withoutMiddleware(ClientProtocol::WITHOUT_WEB_STATE)->group(function () {
        Route::post('/api/app/v1/test-post', fn () => response()->json(['ok' => true]));
        Route::get('/api/app/v1/test-forbidden', fn () => throw new AuthorizationException);
        Route::get('/api/app/v1/test-denied', fn () => throw new AccessDeniedHttpException);
        Route::get('/api/app/v1/test-auth', fn () => throw new AuthenticationException);
        Route::post('/api/app/v1/test-validate', fn (Request $request) => $request->validate(['name' => 'required']));
        Route::get('/api/app/v1/test-throttled', fn () => response()->json(['ok' => true]))->middleware('throttle:1,1');
    });
    Route::middleware(['web', 'client.protocol:cli'])->withoutMiddleware(ClientProtocol::WITHOUT_WEB_STATE)->group(function () {
        Route::get('/api/cli/v1/test-forbidden', fn () => throw new AuthorizationException);
        Route::get('/api/cli/v1/test-auth', fn () => throw new AuthenticationException);
    });
});

it('renders each failure in the client error shape', function () {
    $headers = ['X-Yak-Protocol' => '1'];

    $this->getJson('/api/app/v1/test-missing', $headers)->assertNotFound()->assertExactJson(['error' => 'not_found', 'message' => 'Not found.']);
    $this->postJson('/api/app/v1/test-validate', [], $headers)->assertStatus(422)->assertJsonPath('error', 'validation_failed')->assertJsonStructure(['details' => ['errors' => ['name']]]);
    $this->getJson('/api/app/v1/test-forbidden', $headers)->assertForbidden()->assertJsonPath('error', 'forbidden');
    $this->getJson('/api/app/v1/test-denied', $headers)->assertForbidden()->assertJsonPath('error', 'forbidden');
    $this->getJson('/api/app/v1/test-auth', $headers)->assertUnauthorized()->assertExactJson(['error' => 'unauthenticated', 'message' => 'Pair this device with Yak again.']);
});

it('answers a repeated request with 429 and Retry-After', function () {
    $headers = ['X-Yak-Protocol' => '1'];

    $this->getJson('/api/app/v1/test-throttled', $headers)->assertOk();
    $this->getJson('/api/app/v1/test-throttled', $headers)->assertStatus(429)->assertJsonPath('error', 'rate_limited')->assertHeader('Retry-After');
});

it('keeps the CLI wording on CLI routes', function () {
    $headers = ['X-Yak-Protocol' => '1'];

    $this->getJson('/api/cli/v1/test-auth', $headers)->assertUnauthorized()->assertExactJson(['error' => 'unauthenticated', 'message' => 'Sign in with yak login.']);
    $this->getJson('/api/cli/v1/test-forbidden', $headers)->assertForbidden()->assertJsonPath('error', 'forbidden');
});

it('sets no cookie and needs no csrf token on app routes', function () {
    $response = $this->postJson('/api/app/v1/test-post', [], ['X-Yak-Protocol' => '1'])->assertOk();
    expect($response->headers->getCookies())->toBeEmpty();
});
