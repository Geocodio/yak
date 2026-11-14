<?php

use App\Models\Device;

it('revokes phones idle for more than 90 days and nothing else', function () {
    $idle = Device::factory()->phone()->create(['last_seen_at' => now()->subDays(91)]);
    $active = Device::factory()->phone()->create(['last_seen_at' => now()->subDays(89)]);
    $neverSeenOld = Device::factory()->phone()->create(['last_seen_at' => null, 'created_at' => now()->subDays(91)]);
    $neverSeenNew = Device::factory()->phone()->create(['last_seen_at' => null, 'created_at' => now()->subDays(5)]);
    $computer = Device::factory()->create(['last_seen_at' => now()->subDays(400)]);

    $this->artisan('yak:revoke-idle-phones')->assertSuccessful();

    expect($idle->fresh()->isRevoked())->toBeTrue()
        ->and($neverSeenOld->fresh()->isRevoked())->toBeTrue()
        ->and($active->fresh()->isRevoked())->toBeFalse()
        ->and($neverSeenNew->fresh()->isRevoked())->toBeFalse()
        ->and($computer->fresh()->isRevoked())->toBeFalse();
});

it('does nothing on an empty table and is safe to repeat', function () {
    $this->artisan('yak:revoke-idle-phones')->assertSuccessful();

    $idle = Device::factory()->phone()->create(['last_seen_at' => now()->subDays(120)]);
    $this->artisan('yak:revoke-idle-phones')->assertSuccessful();
    $revokedAt = $idle->fresh()->revoked_at;
    $this->artisan('yak:revoke-idle-phones')->assertSuccessful();

    expect($idle->fresh()->revoked_at->equalTo($revokedAt))->toBeTrue();
});
