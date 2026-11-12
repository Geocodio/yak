<?php

use App\Http\Middleware\CliProtocol;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'cli.protocol'])->withoutMiddleware(CliProtocol::WITHOUT_WEB_STATE)->group(function () {
        Route::get('/api/cli/v1/test-ping', fn () => response()->json(['ok' => true]));
        Route::post('/api/cli/v1/test-post', fn () => response()->json(['ok' => true]));
        Route::get('/api/cli/v1/test-fail', fn () => abort(404));
        Route::post('/api/cli/v1/test-validate', fn (Request $request) => $request->validate(['name' => 'required']));
    });
});

it('answers 426 for a missing protocol header with the range in body and headers', function () {
    $this->getJson('/api/cli/v1/test-ping')
        ->assertStatus(426)
        ->assertHeader('X-Yak-Protocol', '1')
        ->assertHeader('X-Yak-Min-Protocol', '1')
        ->assertJson(['error' => 'protocol_too_old', 'details' => ['min_protocol' => 1, 'current_protocol' => 1, 'client_protocol' => 0]]);
});

it('answers 426 for header 0 or a non-integer', function (string $value) {
    $this->getJson('/api/cli/v1/test-ping', ['X-Yak-Protocol' => $value])->assertStatus(426);
})->with(['0', 'abc']);

it('passes header 1 and stamps both headers', function () {
    $this->getJson('/api/cli/v1/test-ping', ['X-Yak-Protocol' => '1'])
        ->assertOk()
        ->assertHeader('X-Yak-Protocol', '1')
        ->assertHeader('X-Yak-Min-Protocol', '1');
});

it('accepts a newer client', function () {
    $this->getJson('/api/cli/v1/test-ping', ['X-Yak-Protocol' => '9'])->assertOk();
});

it('stamps the headers on error responses', function () {
    $this->getJson('/api/cli/v1/test-fail', ['X-Yak-Protocol' => '1'])
        ->assertNotFound()
        ->assertHeader('X-Yak-Protocol', '1')
        ->assertJson(['error' => 'not_found']);
});

it('sets no cookie on a successful call', function () {
    $response = $this->getJson('/api/cli/v1/test-ping', ['X-Yak-Protocol' => '1'])->assertOk();

    expect($response->headers->getCookies())->toBeEmpty();
});

it('accepts a POST without a csrf token', function () {
    $this->postJson('/api/cli/v1/test-post', [], ['X-Yak-Protocol' => '1'])->assertOk();
});

it('renders validation failures as validation_failed', function () {
    $this->postJson('/api/cli/v1/test-validate', [], ['X-Yak-Protocol' => '1'])
        ->assertStatus(422)
        ->assertJson(['error' => 'validation_failed'])
        ->assertJsonStructure(['message', 'details' => ['errors' => ['name']]]);
});
