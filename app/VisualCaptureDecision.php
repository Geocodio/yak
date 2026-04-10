<?php

namespace App;

use App\Enums\TaskMode;
use App\Models\Repository;

class VisualCaptureDecision
{
    /** @var array<int, string> */
    private const VIDEO_KEYWORDS = [
        'animation',
        'transition',
        'video',
        'scroll',
        'drag',
        'carousel',
        'slider',
        'loading',
        'spinner',
    ];

    /** @var array<int, string> */
    private const UI_KEYWORDS = [
        'button',
        'form',
        'modal',
        'page',
        'layout',
        'style',
        'css',
        'tailwind',
        'ui',
        'ux',
        'design',
        'component',
        'view',
        'template',
        'blade',
        'livewire',
        'frontend',
        'responsive',
        'color',
        'font',
        'image',
        'icon',
        'header',
        'footer',
        'navbar',
        'sidebar',
        'dashboard',
    ];

    /**
     * Determine the visual capture mode for a task.
     *
     * @return string One of: none, screenshots, screenshots_video
     */
    public static function determine(TaskMode $mode, Repository $repository, string $description): string
    {
        if ($mode === TaskMode::Research) {
            return 'none';
        }

        if (empty($repository->dev_url)) {
            return 'none';
        }

        $descriptionLower = strtolower($description);

        foreach (self::VIDEO_KEYWORDS as $keyword) {
            if (str_contains($descriptionLower, $keyword)) {
                return 'screenshots_video';
            }
        }

        foreach (self::UI_KEYWORDS as $keyword) {
            if (str_contains($descriptionLower, $keyword)) {
                return 'screenshots';
            }
        }

        return 'screenshots';
    }
}
