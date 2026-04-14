<?php

use App\DataTransferObjects\InstalledPlugin;
use App\Services\SkillManager;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir() . '/yak-skills-' . uniqid();
    File::makeDirectory($this->tmp, recursive: true);
    config()->set('yak.plugins_dir', $this->tmp);
    config()->set('yak.skills_dir', $this->tmp . '/bundled');
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

it('lists installed plugins from installed_plugins.json', function () {
    File::put($this->tmp . '/installed_plugins.json', json_encode([
        'version' => 2,
        'plugins' => [
            'code-review@claude-plugins-official' => [[
                'scope' => 'user',
                'installPath' => '/home/yak/.claude/plugins/cache/claude-plugins-official/code-review/abc123',
                'version' => 'abc123',
                'installedAt' => '2026-03-01T12:00:00Z',
                'lastUpdated' => '2026-04-10T12:00:00Z',
                'gitCommitSha' => 'abc123def4567890',
            ]],
        ],
    ]));

    $plugins = app(SkillManager::class)->listInstalled();

    expect($plugins)->toHaveCount(1);
    expect($plugins->first())->toBeInstanceOf(InstalledPlugin::class)
        ->and($plugins->first()->name)->toBe('code-review')
        ->and($plugins->first()->marketplace)->toBe('claude-plugins-official')
        ->and($plugins->first()->version)->toBe('abc123')
        ->and($plugins->first()->gitCommitSha)->toBe('abc123def4567890')
        ->and($plugins->first()->enabled)->toBeTrue();
});

it('marks plugins disabled when listed in config.json', function () {
    File::put($this->tmp . '/installed_plugins.json', json_encode([
        'version' => 2,
        'plugins' => [
            'code-review@official' => [[
                'scope' => 'user',
                'installPath' => '/x',
                'version' => '1',
                'installedAt' => '2026-03-01T12:00:00Z',
            ]],
        ],
    ]));
    File::put($this->tmp . '/config.json', json_encode([
        'disabledPlugins' => ['code-review@official'],
    ]));

    $plugin = app(SkillManager::class)->listInstalled()->first();

    expect($plugin->enabled)->toBeFalse();
});

it('returns empty collection when installed_plugins.json is missing', function () {
    expect(app(SkillManager::class)->listInstalled())->toHaveCount(0);
});
