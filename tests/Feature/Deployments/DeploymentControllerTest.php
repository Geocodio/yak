<?php

use App\Models\BranchDeployment;
use App\Models\DeploymentLog;
use App\Models\User;
use App\Support\HibernationDuration;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('index renders active deployments by default and hides destroyed ones', function () {
    $active = BranchDeployment::factory()->running()->create();
    BranchDeployment::factory()->destroyed()->create();

    $this->get(route('deployments'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployments/Index')
            ->has('deployments.data', 1, fn (Assert $row) => $row
                ->where('hostname', $active->hostname)
                ->etc())
            ->where('filters.status', 'active'));
});

test('index filters by status', function () {
    BranchDeployment::factory()->running()->create(['hostname' => 'running-one.yak.example.com']);
    BranchDeployment::factory()->hibernated()->create(['hostname' => 'hib-one.yak.example.com']);

    $this->get(route('deployments', ['status' => 'hibernated']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployments/Index')
            ->has('deployments.data', 1, fn (Assert $row) => $row
                ->where('hostname', 'hib-one.yak.example.com')
                ->etc())
            ->where('filters.status', 'hibernated'));
});

test('index status=all includes destroyed deployments', function () {
    BranchDeployment::factory()->running()->create();
    BranchDeployment::factory()->destroyed()->create();

    $this->get(route('deployments', ['status' => 'all']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployments/Index')
            ->has('deployments.data', 2));
});

test('show renders deployment props', function () {
    $deployment = BranchDeployment::factory()->running()->create(['hostname' => 'foo.yak.example.com']);

    DeploymentLog::record($deployment, 'info', 'lifecycle', 'Deployment ready');

    $this->get(route('deployments.show', $deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployments/Show')
            ->where('deployment.hostname', 'foo.yak.example.com')
            ->where('deployment.status', 'running')
            ->where('deployment.tone', 'ok')
            ->has('hibernation')
            ->has('manifest')
            ->where('shareLink', null)
            ->where('mintedUrl', null)
            ->has('logs', 1, fn (Assert $log) => $log
                ->where('phase', 'lifecycle')
                ->where('message', 'Deployment ready')
                ->etc())
            ->has('pollInterval'));
});

test('show reports a failure reason when the deployment failed', function () {
    $deployment = BranchDeployment::factory()->failed('boom')->create();

    $this->get(route('deployments.show', $deployment))
        ->assertInertia(fn (Assert $page) => $page
            ->where('deployment.failure', 'boom')
            ->where('deployment.tone', 'fail'));
});

test('show uses a faster poll interval while the deployment transitions', function () {
    $deployment = BranchDeployment::factory()->starting()->create();

    $this->get(route('deployments.show', $deployment))
        ->assertInertia(fn (Assert $page) => $page->where('pollInterval', 2000));
});

test('show uses a slower poll interval once settled', function () {
    $deployment = BranchDeployment::factory()->running()->create();

    $this->get(route('deployments.show', $deployment))
        ->assertInertia(fn (Assert $page) => $page->where('pollInterval', 15000));
});

test('index marks a long-lived deployment row as long-lived with its hibernation window', function () {
    $deployment = BranchDeployment::factory()->running()->longLived()->create(['branch_name' => 'feat/keep-alive']);

    $this->get(route('deployments'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('deployments.data', 1, fn (Assert $row) => $row
                ->where('longLived', true)
                ->where('hibernatesAfter', HibernationDuration::humanize($deployment->effectiveIdleMinutes()))
                ->etc()));
});

test('index marks a standard deployment row as not long-lived', function () {
    BranchDeployment::factory()->running()->create(['branch_name' => 'feat/x', 'long_lived' => false]);

    $this->get(route('deployments'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('deployments.data', 1, fn (Assert $row) => $row
                ->where('longLived', false)
                ->etc()));
});

test('show reports longLived true for a long-lived deployment', function () {
    $deployment = BranchDeployment::factory()->running()->longLived()->create();

    $this->get(route('deployments.show', $deployment))
        ->assertInertia(fn (Assert $page) => $page->where('hibernation.longLived', true));
});

test('show reports autoLongLived true for an active release branch', function () {
    $deployment = BranchDeployment::factory()->running()->longLived()->create([
        'branch_name' => 'release/1.0',
    ]);

    $this->get(route('deployments.show', $deployment))
        ->assertInertia(fn (Assert $page) => $page->where('hibernation.autoLongLived', true));
});

test('show reports autoLongLived false for a non-release branch', function () {
    $deployment = BranchDeployment::factory()->running()->create([
        'branch_name' => 'feat/not-a-release',
        'long_lived' => false,
    ]);

    $this->get(route('deployments.show', $deployment))
        ->assertInertia(fn (Assert $page) => $page->where('hibernation.autoLongLived', false));
});
