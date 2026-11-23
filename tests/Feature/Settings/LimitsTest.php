<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\EditableSettings;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

function limitValues(array $overrides = []): array
{
    $settings = app(EditableSettings::class);
    $values = [];

    foreach ($settings->definitions() as $definition) {
        $values[$definition['id']] = $settings->currentValue($definition);
    }

    return ['values' => [...$values, ...$overrides]];
}

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
});

it('keeps the page and every save away from members', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('settings.limits'))->assertForbidden();
    $this->put(route('settings.limits.update'), limitValues(['awake_cap' => 9]))->assertForbidden();
    $this->post(route('settings.limits.reset'))->assertForbidden();
    expect(Setting::query()->count())->toBe(0);
});

it('treats a config admin as an admin', function (): void {
    $member = User::factory()->create();
    config(['claude-accounts.admin_user_ids' => [$member->id]]);

    $this->actingAs($member)->get(route('settings.limits'))->assertOk();
});

it('lists every setting with its default and current value', function (): void {
    $settings = app(EditableSettings::class);
    $settings->store($settings->definitionForKey('yak.sandbox.memory_limit'), 6);
    $settings->refresh();

    $this->actingAs($this->admin)->get(route('settings.limits'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Limits')
            ->has('groups', 6)
            ->where('groups.0.id', 'sandboxes')
            ->where('groups.0.settings.0.id', 'awake_cap')
            ->where('groups.0.settings.0.default', 8)
            ->where('groups.0.settings.0.isOverridden', false)
            ->where('groups.0.settings.3.default', 8)
            ->where('groups.0.settings.3.value', 6)
            ->where('groups.0.settings.3.isOverridden', true)
            ->where('groups.0.settings.3.unit', 'GB')
            ->missing('suggestion'));
});

it('applies a saved value to config and shows the default beside it', function (): void {
    config(['yak.threads.awake_cap' => 8]);
    app(EditableSettings::class)->refresh();

    $this->actingAs($this->admin)->put(route('settings.limits.update'), limitValues(['awake_cap' => 12]))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(config('yak.threads.awake_cap'))->toBe(12)
        ->and(Setting::query()->where('key', 'yak.threads.awake_cap')->value('value'))->toBe(12)
        ->and(Setting::query()->count())->toBe(1);

    $this->get(route('settings.limits'))->assertInertia(fn (Assert $page) => $page
        ->where('groups.0.settings.0.default', 8)
        ->where('groups.0.settings.0.value', 12)
        ->where('groups.0.settings.0.isOverridden', true));
});

it('stores the memory per sandbox as a size string in config', function (): void {
    config(['yak.sandbox.memory_limit' => '8GB']);
    app(EditableSettings::class)->refresh();

    $this->actingAs($this->admin)->put(route('settings.limits.update'), limitValues(['sandbox_memory_gb' => 16]))->assertRedirect();

    expect(config('yak.sandbox.memory_limit'))->toBe('16GB')
        ->and(Setting::query()->where('key', 'yak.sandbox.memory_limit')->value('value'))->toBe(16);
});

it('deletes the row when a field is set back to its default', function (): void {
    config(['yak.threads.awake_cap' => 8]);
    app(EditableSettings::class)->refresh();

    $this->actingAs($this->admin)->put(route('settings.limits.update'), limitValues(['awake_cap' => 12]));
    $this->put(route('settings.limits.update'), limitValues(['awake_cap' => 8]))->assertRedirect();

    expect(Setting::query()->count())->toBe(0)
        ->and(config('yak.threads.awake_cap'))->toBe(8);
});

it('resets every override to the config value', function (): void {
    config(['yak.threads.awake_cap' => 8, 'yak.daily_budget_usd' => 50.0, 'yak.pr_review.enabled_globally' => true]);
    app(EditableSettings::class)->refresh();

    $this->actingAs($this->admin)->put(route('settings.limits.update'), limitValues(['awake_cap' => 12, 'daily_budget_usd' => 75.5, 'pr_review_enabled' => false]));
    expect(config('yak.threads.awake_cap'))->toBe(12)
        ->and(config('yak.daily_budget_usd'))->toBe(75.5)
        ->and(config('yak.pr_review.enabled_globally'))->toBeFalse();

    $this->post(route('settings.limits.reset'))->assertRedirect();

    expect(Setting::query()->count())->toBe(0)
        ->and(config('yak.threads.awake_cap'))->toBe(8)
        ->and(config('yak.daily_budget_usd'))->toBe(50.0)
        ->and(config('yak.pr_review.enabled_globally'))->toBeTrue();
});

it('reapplies saved overrides when a queue job starts', function (): void {
    config(['yak.threads.awake_cap' => 8]);
    app(EditableSettings::class)->refresh();
    $this->actingAs($this->admin)->put(route('settings.limits.update'), limitValues(['awake_cap' => 12]));

    config(['yak.threads.awake_cap' => 8]);
    Event::dispatch(new JobProcessing('sync', Mockery::mock(Job::class, ['payload' => []])));

    expect(config('yak.threads.awake_cap'))->toBe(12);
});

it('rejects values outside the bounds with a message that says how to fix it', function (array $overrides, string $field, string $message): void {
    $this->actingAs($this->admin)->put(route('settings.limits.update'), limitValues($overrides))
        ->assertSessionHasErrors([$field => $message]);

    expect(Setting::query()->count())->toBe(0);
})->with([
    'server cap below one' => [['awake_cap' => 0], 'values.awake_cap', 'Awake sandboxes on this server must be at least 1. Enter 1 or more.'],
    'not a whole number' => [['max_turns' => 'many'], 'values.max_turns', 'Turns per task must be a whole number, like 4.'],
    'budget below the minimum' => [['max_budget_per_task' => 0.1], 'values.max_budget_per_task', 'Budget per task must be at least 0.5. Enter 0.5 or more.'],
    'person cap above the server cap' => [['awake_cap' => 4, 'person_awake_cap' => 6, 'child_awake_limit' => 2], 'values.person_awake_cap', 'Awake sandboxes per person cannot be higher than awake sandboxes on this server (4). Lower it, or raise the server limit first.'],
    'child limit above the person cap' => [['awake_cap' => 8, 'person_awake_cap' => 3, 'child_awake_limit' => 5], 'values.child_awake_limit', 'Awake sub-threads per thread cannot be higher than awake sandboxes per person (3). Lower it, or raise the per person limit first.'],
]);

it('checks ordering against the value in effect when only one setting is sent', function (): void {
    config(['yak.threads.awake_cap' => 8, 'yak.threads.person_awake_cap' => 4, 'yak.threads.child_awake_limit' => 3]);

    $this->actingAs($this->admin)->put(route('settings.limits.update'), ['values' => ['awake_cap' => 3]])
        ->assertSessionHasErrors('values.person_awake_cap');

    $this->put(route('settings.limits.update'), ['values' => ['awake_cap' => 12]])->assertSessionHasNoErrors();
    expect(config('yak.threads.awake_cap'))->toBe(12);
});

it('survives a missing settings table', function (): void {
    Schema::drop('settings');
    Cache::forget(EditableSettings::CACHE_KEY);

    expect(fn () => app(EditableSettings::class)->apply())->not->toThrow(Throwable::class);
});
