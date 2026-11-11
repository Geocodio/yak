<?php

use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Models\User;

test('the app shell renders the sidebar nav and the command palette opens', function () {
    $this->actingAs(User::factory()->create());
    Repository::factory()->create(['slug' => 'acme/widgets', 'name' => 'Acme Widgets']);
    BranchDeployment::factory()->running()->count(3)->create();

    $page = visit('/repos');

    $page->assertNoJavaScriptErrors();

    // The rail groups the destinations into sections; each section drawer
    // lists its pages, and the thread list is the default.
    $sections = [
        'operations' => ['Repositories', 'Deployments', 'PR reviews'],
        'insights' => ['Analytics', 'Costs', 'Health'],
        'settings' => ['Prompts', 'Skills', 'MCP servers', 'System'],
    ];

    $page->click('[data-testid="rail-operations"]')
        ->assertVisible('[data-testid="ops-repo-acme/widgets"]')
        ->assertSee('3 running')
        ->assertSee('Quiet');

    foreach ($sections as $section => $labels) {
        $page->click('[data-testid="rail-' . $section . '"]')
            ->assertVisible('[data-testid="section-panel-' . $section . '"]');

        foreach ($labels as $label) {
            $page->assertSee($label);
        }
    }

    // Channels lives in the Settings drawer, which is still open here.
    $page->assertVisible('[data-testid="section-panel-settings"] a[href="/channels"]');

    $page->assertSee('Account')
        ->assertSee('Agent')
        ->assertSee('Workspace')
        ->assertDontSee('Your profile, prompts')
        ->click('[data-testid="section-panel-collapse"]')
        ->assertMissing('[data-testid="sidebar-panel"]');

    $page->keys('[data-testid="app-shell"]', ['Meta+k']);

    $page->assertVisible('[data-testid="app-palette"]');

    // The palette lists every destination, so Channels is reachable from
    // any page.
    $page->assertVisible('[data-testid="palette-item-Channels"]');

    $page->assertNoJavaScriptErrors();
});

test('the sidebar shows the Yak brand lockup', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/tasks');

    $page->assertVisible('[data-testid="app-brand"]')
        ->assertMissing('[data-testid="mobile-nav-trigger"]')
        ->assertNoJavaScriptErrors();
});

test('on a phone the sidebar folds into a top bar, a floating tab bar and a navigation drawer', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/tasks')->on()->mobile();

    // The desktop sidebar is hidden below `lg` and there is no app bar; the
    // floating bar carries the daily pages plus the More slot that opens the
    // drawer, and the drawer carries search.
    $page->assertMissing('[data-testid="sidebar"]')
        ->assertVisible('[data-testid="mobile-tab-bar"]')
        ->assertVisible('[data-testid="mobile-tab-tasks"][aria-current="page"]')
        ->assertVisible('[data-testid="mobile-tab-reviews"]')
        ->assertVisible('[data-testid="mobile-tab-repos"]')
        ->assertMissing('[data-testid="mobile-nav"]')
        ->click('[data-testid="mobile-nav-trigger"]')
        ->assertVisible('[data-testid="mobile-nav"]')
        ->assertVisible('[data-testid="mobile-section-switcher"]')
        ->click('[data-testid="mobile-section-insights"]')
        ->assertVisible('[data-testid="mobile-nav"] a[href="/health"]')
        ->click('[data-testid="mobile-section-operations"]')
        ->assertVisible('[data-testid="mobile-nav"] a[href="/repos"]')
        ->click('[data-testid="mobile-nav"] a[href="/repos"]')
        ->assertPathIs('/repos')
        ->assertMissing('[data-testid="mobile-nav"]')
        ->assertVisible('[data-testid="mobile-tab-repos"][aria-current="page"]')
        ->assertNoJavaScriptErrors();
});

test('on a phone the round button opens the new thread page from any page', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/repos')->on()->mobile();

    $page->click('[data-testid="tab-new-task"]')
        ->assertPathIs('/threads/new')
        ->assertVisible('[data-testid="start-thread-description"]')
        ->assertNoJavaScriptErrors();
});
