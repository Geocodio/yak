<?php

use App\Enums\ClaudeAccountKind;
use App\Models\ClaudeAccount;
use App\Services\Threads\CredentialReferences;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->account = ClaudeAccount::factory()->create(['secret' => 'sk-ant-secret-value', 'kind' => ClaudeAccountKind::ApiKey]);
});

it('redeems a reference exactly once', function () {
    $references = app(CredentialReferences::class);
    $reference = $references->issue($this->account);

    expect($references->redeem($reference))->toBe(['type' => 'api_key', 'value' => 'sk-ant-secret-value'])
        ->and($references->redeem($reference))->toBeNull();
});

it('stores the credential encrypted and under a hashed key', function () {
    $reference = app(CredentialReferences::class)->issue($this->account);
    $stored = Cache::store(config('yak.credential_reference_store'))->get('credential-reference:' . hash('sha256', $reference));

    expect($stored)->toBeString()->not->toContain('sk-ant-secret-value');
});

it('expires after sixty seconds', function () {
    $references = app(CredentialReferences::class);
    $reference = $references->issue($this->account);

    $this->travel(61)->seconds();

    expect($references->redeem($reference))->toBeNull();
});

it('refuses a reference it never issued', function () {
    expect(app(CredentialReferences::class)->redeem('nope'))->toBeNull();
});

it('keeps the reference store out of redis', function () {
    expect(config('yak.credential_reference_store'))->not->toBe('redis');
});
