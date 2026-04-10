<?php

use App\Enums\TaskMode;
use App\Models\Repository;
use App\VisualCaptureDecision;

/*
|--------------------------------------------------------------------------
| Research Mode
|--------------------------------------------------------------------------
*/

test('research mode returns none', function () {
    $repo = new Repository(['dev_url' => 'http://localhost:8000']);

    $result = VisualCaptureDecision::determine(TaskMode::Research, $repo, 'Fix the button on the page');

    expect($result)->toBe('none');
});

/*
|--------------------------------------------------------------------------
| Repository Without dev_url
|--------------------------------------------------------------------------
*/

test('repo without dev_url returns none', function () {
    $repo = new Repository;

    $result = VisualCaptureDecision::determine(TaskMode::Fix, $repo, 'Fix the button on the page');

    expect($result)->toBe('none');
});

test('repo with empty dev_url returns none', function () {
    $repo = new Repository(['dev_url' => '']);

    $result = VisualCaptureDecision::determine(TaskMode::Fix, $repo, 'Fix the button');

    expect($result)->toBe('none');
});

test('repo with null dev_url returns none', function () {
    $repo = new Repository(['dev_url' => null]);

    $result = VisualCaptureDecision::determine(TaskMode::Fix, $repo, 'Fix the button');

    expect($result)->toBe('none');
});

/*
|--------------------------------------------------------------------------
| UI Keywords Trigger Screenshots
|--------------------------------------------------------------------------
*/

test('UI keywords trigger screenshots', function () {
    $repo = new Repository(['dev_url' => 'http://localhost:8000']);

    $keywords = ['button', 'form', 'modal', 'layout', 'css', 'tailwind', 'ui', 'component', 'blade', 'livewire', 'frontend', 'dashboard'];

    foreach ($keywords as $keyword) {
        $result = VisualCaptureDecision::determine(TaskMode::Fix, $repo, "Fix the {$keyword} issue");

        expect($result)->toBe('screenshots', "Expected 'screenshots' for keyword '{$keyword}'");
    }
});

/*
|--------------------------------------------------------------------------
| Video Keywords Trigger Screenshots + Video
|--------------------------------------------------------------------------
*/

test('video keywords trigger screenshots_video', function () {
    $repo = new Repository(['dev_url' => 'http://localhost:8000']);

    $keywords = ['animation', 'transition', 'video', 'scroll', 'drag', 'carousel', 'slider', 'loading', 'spinner'];

    foreach ($keywords as $keyword) {
        $result = VisualCaptureDecision::determine(TaskMode::Fix, $repo, "Fix the {$keyword} behavior");

        expect($result)->toBe('screenshots_video', "Expected 'screenshots_video' for keyword '{$keyword}'");
    }
});

/*
|--------------------------------------------------------------------------
| Default With dev_url
|--------------------------------------------------------------------------
*/

test('default with dev_url is screenshots', function () {
    $repo = new Repository(['dev_url' => 'http://localhost:8000']);

    $result = VisualCaptureDecision::determine(TaskMode::Fix, $repo, 'Fix the database query performance');

    expect($result)->toBe('screenshots');
});

test('setup mode with dev_url defaults to screenshots', function () {
    $repo = new Repository(['dev_url' => 'http://localhost:8000']);

    $result = VisualCaptureDecision::determine(TaskMode::Setup, $repo, 'Set up the project');

    expect($result)->toBe('screenshots');
});
