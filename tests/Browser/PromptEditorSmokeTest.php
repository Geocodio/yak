<?php

use App\Models\User;

test('prompts page renders and CodeMirror mounts', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('prompts'));

    $page->assertNoJavaScriptErrors();
    $page->assertSee('Prompts');
    $page->assertSee('System Rules');
    $page->assertSee('Available variables');
    // The editor surface has a data-test attribute. If CodeMirror initialized
    // without errors, assertNoJavaScriptErrors above passed.
    $page->assertPresent('[data-test="prompt-editor-surface"]');
});

test('prompts page has no accessibility issues', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('prompts'));

    $page->assertNoAccessibilityIssues();
});

test('selecting a different prompt loads its content', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('prompts'));

    $page->click('[data-test="prompt-item-tasks-linear-fix"]');

    $page->assertSee('Linear Fix');
});
