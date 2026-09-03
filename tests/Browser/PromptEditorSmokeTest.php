<?php

use App\Models\User;

test('prompts page renders and CodeMirror mounts', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('prompts'));

    $page->assertNoJavaScriptErrors();
    $page->assertSee('System Rules');
    $page->assertSee('Variables');
    // The editor surface is CodeMirror's mount point. If it initialized
    // without errors, assertNoJavaScriptErrors above passed.
    $page->assertPresent('[data-testid="prompt-editor"]');
});

test('prompts page has no accessibility issues', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('prompts'));

    $page->assertNoAccessibilityIssues();
});

test('selecting a different prompt loads its content', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('prompts'));

    $page->click('[data-testid="prompt-item-tasks-linear-fix"]');

    $page->assertSee('Linear Fix');
});

test('toggling the diff view renders the merge editor', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('prompts'));

    $page->click('[data-testid="toggle-diff"]');

    $page->assertPresent('[data-testid="prompt-diff"]');
});
