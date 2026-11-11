<?php

use App\Http\Resources\AccountPickerData;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\User;

function checked(ClaudeAccount $account): ClaudeAccount
{
    $account->forceFill(['validated_at' => now()])->save();

    return $account;
}

it('lists the person own and system accounts, never another person', function () {
    $me = User::factory()->create();
    $mine = checked(ClaudeAccount::factory()->for($me, 'owner')->create(['name' => 'B mine']));
    $system = checked(ClaudeAccount::factory()->system()->create(['name' => 'A yak']));
    checked(ClaudeAccount::factory()->create(['name' => 'theirs']));

    $data = AccountPickerData::forUser($me);

    expect(collect($data['accounts'])->pluck('id')->all())->toBe([$system->id, $mine->id])
        ->and($data['accounts'][0]['usable'])->toBeTrue()
        ->and($data['models'])->toBe(['opus', 'sonnet', 'haiku'])
        ->and(json_encode($data))->not->toContain('secret');
});

it('gives a guest system accounts only', function () {
    $system = checked(ClaudeAccount::factory()->system()->create());
    checked(ClaudeAccount::factory()->create());

    expect(collect(AccountPickerData::forUser(null)['accounts'])->pluck('id')->all())->toBe([$system->id]);
});

it('excludes unchecked throwaway rows and flags unusable accounts', function () {
    $me = User::factory()->create();
    ClaudeAccount::factory()->system()->create(['name' => 'validation-abc']);
    $expired = checked(ClaudeAccount::factory()->for($me, 'owner')->expired()->create());

    $accounts = AccountPickerData::forUser($me)['accounts'];

    expect($accounts)->toHaveCount(1)->and($accounts[0]['id'])->toBe($expired->id)->and($accounts[0]['usable'])->toBeFalse();
});

it('reports the default account per active repository', function () {
    $me = User::factory()->create();
    $mine = checked(ClaudeAccount::factory()->for($me, 'owner')->default()->create(['default_model' => 'haiku']));
    $repository = Repository::factory()->create(['is_active' => true]);

    expect(AccountPickerData::forUser($me)['defaultsByRepo'][$repository->slug])->toBe(['accountId' => $mine->id, 'model' => 'haiku']);
});
