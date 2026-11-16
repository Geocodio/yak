<?php

use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('opens the system section on the package system page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('system'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('System/Index')
            ->where('telemetryBase', '/telemetry-ui')
            ->where('path', 'p/system'));
});

it('serves the package pages under the system section', function (string $path) {
    $this->actingAs(User::factory()->create())
        ->get('/system/' . $path)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('System/Index')->where('path', $path));
})->with(['p/dashboard', 'p/queues', 'explore/traces', 'entity/route', 'traces/4bf92f3577b34da6a3ce929d0e0e4736', 'errors/abc123']);

it('refuses paths the package does not serve', function () {
    $this->actingAs(User::factory()->create())
        ->get('/system/settings/anything')
        ->assertNotFound();
});

it('redirects guests away from the system section', function () {
    $this->get(route('system'))->assertRedirect(route('login'));
});

it('lets signed-in users read the telemetry api', function () {
    $this->actingAs(User::factory()->create())
        ->get('/telemetry-ui/api/v2/bootstrap')
        ->assertOk();
});

it('blocks guests from the telemetry api', function () {
    $this->get('/telemetry-ui/api/v2/bootstrap')->assertForbidden();
});

it('adds the app disk and sandbox pool panels to the package system page', function () {
    $panels = $this->actingAs(User::factory()->create())
        ->getJson('/telemetry-ui/api/v2/pages/system')
        ->assertOk()
        ->json('panels.*.id');

    expect($panels)->toContain('yak-app-disk', 'yak-sandbox-pool')
        ->not->toContain('yak-cpu-utilization', 'yak-memory-utilization');
});

it('refuses ids that could steer the package api elsewhere', function (string $path) {
    $this->actingAs(User::factory()->create())
        ->get('/system/' . $path)
        ->assertNotFound();
})->with([
    'traversal in a trace id' => 'traces/..%2F..%2Fpanels%2Fsystem-cpu',
    'dot segment' => 'traces/..',
    'extra segment' => 'p/system/extra',
    'query characters' => 'errors/abc%3Fx%3D1',
    'empty id' => 'traces/',
]);

it('gives admins the awake slots as counts only and members null', function () {
    $admin = User::factory()->create();
    $other = User::factory()->create(['name' => 'Tessa Teammate']);
    config(['claude-accounts.admin_user_ids' => [$admin->id], 'yak.threads.awake_cap' => 8, 'yak.threads.person_awake_cap' => 4]);
    Thread::factory()->private($other)->create(['title' => 'Secret pricing plan', 'sandbox_state' => ThreadSandboxState::Running]);
    Thread::factory()->create(['owner_user_id' => $other->id, 'sandbox_state' => ThreadSandboxState::Running]);
    Thread::factory()->create(['owner_user_id' => $admin->id, 'sandbox_state' => ThreadSandboxState::Running]);

    $response = $this->actingAs($admin)->get(route('system'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('awakeSlots.total', 8)
        ->where('awakeSlots.used', 3)
        ->where('awakeSlots.perPersonCap', 4)
        ->where('awakeSlots.people.0.name', 'Tessa Teammate')
        ->where('awakeSlots.people.0.initials', 'TT')
        ->where('awakeSlots.people.0.awake', 2));
    expect($response->getContent())->not->toContain('Secret pricing plan');

    $this->actingAs(User::factory()->create())->get(route('system'))
        ->assertInertia(fn (Assert $page) => $page->where('awakeSlots', null));
});
