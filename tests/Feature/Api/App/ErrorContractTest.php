<?php

use App\Http\Middleware\ClientProtocol;
use App\Models\Device;
use App\Services\Devices\DeviceTokens;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

beforeEach(function () {
    Route::middleware(['web', 'client.protocol:app'])->withoutMiddleware(ClientProtocol::WITHOUT_WEB_STATE)->group(function () {
        Route::get('/api/app/v1/test-db-down', fn () => throw new QueryException('mysql', 'select secret from x', [], new PDOException('SQLSTATE[HY000] [2002]')));
        Route::post('/api/app/v1/test-post', fn () => response()->json(['ok' => true]));
        Route::get('/api/app/v1/test-forbidden', fn () => throw new AuthorizationException);
        Route::get('/api/app/v1/test-denied', fn () => throw new AccessDeniedHttpException);
        Route::get('/api/app/v1/test-auth', fn () => throw new AuthenticationException);
        Route::post('/api/app/v1/test-validate', fn (Request $request) => $request->validate(['name' => 'required']));
        Route::get('/api/app/v1/test-boom', fn () => throw new RuntimeException('secret detail'));
        Route::get('/api/app/v1/test-conflict', fn () => throw new ConflictHttpException);
        Route::get('/api/app/v1/test-model-missing', fn () => throw new ModelNotFoundException);
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

it('answers 503 unavailable without leaking SQL when the database is down, whatever the Accept header', function () {
    $response = $this->get('/api/app/v1/test-db-down', ['X-Yak-Protocol' => '1']);

    $response->assertStatus(503)->assertExactJson(['error' => 'unavailable', 'message' => 'Yak cannot answer right now. Try again in a moment.']);
    expect($response->getContent())->not->toContain('SQLSTATE')->not->toContain('select');
});

it('answers 405 and unknown paths in the error shape with the protocol headers', function (string $method, string $uri, int $status, string $error) {
    $response = $this->json($method, $uri, [], ['X-Yak-Protocol' => '1']);

    $response->assertStatus($status)->assertJsonPath('error', $error)->assertJsonStructure(['error', 'message'])
        ->assertHeader('X-Yak-Protocol', (string) config('yak.app.protocol'))->assertHeader('X-Yak-Min-Protocol', (string) config('yak.app.min_protocol'));
    expect($response->headers->get('Content-Type'))->toContain('application/json');
})->with([
    'wrong method' => ['GET', '/api/app/v1/device/pair', 405, 'method_not_allowed'],
    'unknown path' => ['GET', '/api/app/v1/nope', 404, 'not_found'],
    'other http exception' => ['GET', '/api/app/v1/test-conflict', 409, 'conflict'],
    'missing model' => ['GET', '/api/app/v1/test-model-missing', 404, 'not_found'],
]);

it('answers 405 on the CLI API with the CLI protocol headers', function () {
    $this->getJson('/api/cli/v1/device/token', ['X-Yak-Protocol' => '1'])->assertStatus(405)->assertJsonPath('error', 'method_not_allowed')
        ->assertHeader('X-Yak-Protocol', (string) config('yak.cli.protocol'));
});

it('answers an unexpected failure with server_error, never HTML and never the detail', function () {
    $response = $this->get('/api/app/v1/test-boom', ['X-Yak-Protocol' => '1']);

    $response->assertStatus(500)->assertExactJson(['error' => 'server_error', 'message' => 'Yak could not handle this request. Try again.'])
        ->assertHeader('X-Yak-Protocol', (string) config('yak.app.protocol'));
    expect($response->getContent())->not->toContain('secret detail');
});

it('stamps the protocol headers on instance discovery and on a 426', function () {
    $this->getJson('/api/app/v1/instance')->assertOk()->assertHeader('X-Yak-Protocol', (string) config('yak.app.protocol'));
    $this->getJson('/api/app/v1/me', ['X-Yak-Protocol' => '0'])->assertStatus(426)->assertHeader('X-Yak-Min-Protocol', (string) config('yak.app.min_protocol'));
});

it('does not touch responses outside the client APIs', function () {
    expect($this->get('/up')->headers->has('X-Yak-Protocol'))->toBeFalse();
});

it('counts failed token checks per address on both APIs', function (string $uri) {
    $bad = ['X-Yak-Protocol' => '1', 'Authorization' => 'Bearer ' . app(DeviceTokens::class)->generate()];

    foreach (range(1, 60) as $ignored) {
        $this->getJson($uri, $bad)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
    }

    $this->getJson($uri, $bad)->assertStatus(429)->assertJsonPath('error', 'rate_limited')->assertHeader('Retry-After');
})->with(['/api/app/v1/me', '/api/cli/v1/me']);

it('does not count successful token checks as failures', function () {
    $token = app(DeviceTokens::class)->generate();
    Device::factory()->phone()->create(['token_hash' => app(DeviceTokens::class)->hash($token)]);

    foreach (range(1, 70) as $ignored) {
        $this->getJson('/api/app/v1/me', ['X-Yak-Protocol' => '1', 'Authorization' => "Bearer {$token}"])->assertOk();
    }
});

it('limits instance discovery to 60 a minute per address', function () {
    foreach (range(1, 60) as $ignored) {
        $this->getJson('/api/app/v1/instance')->assertOk();
    }

    $this->getJson('/api/app/v1/instance')->assertStatus(429)->assertJsonPath('error', 'rate_limited')->assertHeader('X-Yak-Protocol');
});
