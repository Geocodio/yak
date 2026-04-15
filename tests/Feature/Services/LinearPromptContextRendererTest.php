<?php

use App\Services\LinearPromptContextRenderer;

it('renders an issue block with title, description, project, and labels', function () {
    $xml = <<<'XML'
    <issue identifier="ENG-123">
    <title>Fix accessibility on checkout page</title>
    <description>Make it screen-reader friendly</description>
    <team name="Engineering"/>
    <label>bug</label>
    <label>a11y</label>
    <project name="Checkout flow">Faster checkout process</project>
    </issue>
    XML;

    $markdown = app(LinearPromptContextRenderer::class)->render($xml);

    expect($markdown)->toContain('# ENG-123: Fix accessibility on checkout page');
    expect($markdown)->toContain('Make it screen-reader friendly');
    expect($markdown)->toContain('**Project:** Checkout flow');
    expect($markdown)->toContain('**Labels:** bug, a11y');
    expect($markdown)->toContain('**Team:** Engineering');
});

it('renders the primary directive thread with author, timestamp, and body', function () {
    $xml = <<<'XML'
    <issue identifier="ENG-1"><title>t</title></issue>
    <primary-directive-thread comment-id="c1">
    <comment author="John Doe" created-at="2026-01-08 16:33:12">
    Please implement this
    </comment>
    </primary-directive-thread>
    XML;

    $markdown = app(LinearPromptContextRenderer::class)->render($xml);

    expect($markdown)->toContain('## Primary directive');
    expect($markdown)->toContain('**John Doe** _(2026-01-08 16:33:12)_');
    expect($markdown)->toContain('Please implement this');
});

it('renders other threads and guidance blocks when present', function () {
    $xml = <<<'XML'
    <issue identifier="ENG-1"><title>t</title></issue>
    <other-thread comment-id="c2">
    <comment author="Jane" created-at="2026-01-08 10:00:00">Side note</comment>
    </other-thread>
    <guidance>
    <guidance-rule origin="team" team-name="Engineering">Follow coding standards</guidance-rule>
    </guidance>
    XML;

    $markdown = app(LinearPromptContextRenderer::class)->render($xml);

    expect($markdown)->toContain('## Other thread');
    expect($markdown)->toContain('Side note');
    expect($markdown)->toContain('## Guidance');
    expect($markdown)->toContain('Follow coding standards');
});

it('returns an empty string when the XML is empty or unparseable', function () {
    $renderer = app(LinearPromptContextRenderer::class);
    expect($renderer->render(''))->toBe('');
    expect($renderer->render('<unclosed'))->toBe('');
});

it('renders a parent issue block when present', function () {
    $xml = <<<'XML'
    <issue identifier="ENG-5">
    <title>Child</title>
    <parent-issue identifier="ENG-1">
    <title>Parent title</title>
    <description>Parent desc</description>
    </parent-issue>
    </issue>
    XML;

    $markdown = app(LinearPromptContextRenderer::class)->render($xml);

    expect($markdown)->toContain('**Parent issue:** ENG-1 — Parent title');
});
