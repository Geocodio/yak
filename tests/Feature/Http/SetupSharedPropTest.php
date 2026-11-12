<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ClaudeAccount;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;

function setupProp(User $user): array
{
    test()->actingAs($user);
    $response = test()->get(route('settings.claude-accounts'));

    return $response->viewData('page')['props']['setup'];
}

beforeEach(function () {
    config(['app.url' => 'https://yak.example.com/', 'claude-accounts.admin_user_ids' => []]);
});

it('reports a fresh member as having nothing set up', function () {
    expect(setupProp(User::factory()->create()))->toBe([
        'hasOwnAccount' => false,
        'brokenAccount' => null,
        'hasDevice' => false,
        'latestDeviceName' => null,
        'needsYakAccount' => false,
        'installCommand' => 'curl -fsSL https://yak.example.com/install | sh',
        'loginCommand' => 'yak login --url https://yak.example.com',
        'models' => ['opus', 'sonnet', 'haiku'],
        'appName' => config('app.name'),
    ]);
});

it('sees a usable own account', function () {
    $user = User::factory()->create();
    ClaudeAccount::factory()->for($user, 'owner')->create();
    ClaudeAccount::factory()->create();

    $setup = setupProp($user);

    expect($setup['hasOwnAccount'])->toBeTrue()->and($setup['brokenAccount'])->toBeNull();
});

it('reports the first broken own account by name', function () {
    $user = User::factory()->create();
    ClaudeAccount::factory()->for($user, 'owner')->create(['name' => 'Fine']);
    $zeta = ClaudeAccount::factory()->for($user, 'owner')->invalid()->create(['name' => 'Zeta']);
    $alpha = ClaudeAccount::factory()->for($user, 'owner')->expired()->create(['name' => 'Alpha']);

    expect(setupProp($user)['brokenAccount'])->toBe(['id' => $alpha->id, 'name' => 'Alpha']);
});

it('reports the latest active device and ignores revoked ones', function () {
    $user = User::factory()->create();
    Device::factory()->for($user)->create(['name' => 'old', 'created_at' => now()->subDay()]);
    Device::factory()->for($user)->create(['name' => 'newest']);
    Device::factory()->for($user)->revoked()->create(['name' => 'gone', 'created_at' => now()->addDay()]);

    $setup = setupProp($user);

    expect($setup['hasDevice'])->toBeTrue()->and($setup['latestDeviceName'])->toBe('newest');

    $other = User::factory()->create();
    Device::factory()->for($other)->revoked()->create();
    expect(setupProp($other)['hasDevice'])->toBeFalse();
});

it('asks an admin for a Yak account until a usable default exists', function () {
    $admin = User::factory()->admin()->create();
    expect(setupProp($admin)['needsYakAccount'])->toBeTrue();

    ClaudeAccount::factory()->system()->default()->invalid()->create();
    expect(setupProp($admin)['needsYakAccount'])->toBeTrue();

    ClaudeAccount::query()->delete();
    ClaudeAccount::factory()->system()->default()->create();
    expect(setupProp($admin)['needsYakAccount'])->toBeFalse();
});

it('never asks a member for a Yak account', function () {
    expect(setupProp(User::factory()->create())['needsYakAccount'])->toBeFalse();
});

it('shares null with a guest', function () {
    $shared = (new HandleInertiaRequests)->share(Request::create('/'));

    expect(value($shared['setup']))->toBeNull();
});
