<?php

use App\DataTransferObjects\InstalledPlugin;
use App\Exceptions\ClaudeCliException;
use App\Services\SkillManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

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

it('lists bundled skills from the skills directory', function () {
    $skillsDir = $this->tmp . '/bundled';
    File::makeDirectory($skillsDir . '/agent-browser', recursive: true);
    File::put($skillsDir . '/agent-browser/SKILL.md', <<<'MD'
    ---
    name: agent-browser
    description: Browser automation CLI for AI agents.
    ---

    Body
    MD);
    File::makeDirectory($skillsDir . '/polish', recursive: true);
    File::put($skillsDir . '/polish/SKILL.md', "---\nname: polish\ndescription: Visual polish rules.\n---\n");

    $skills = app(SkillManager::class)->listBundledSkills();

    expect($skills)->toHaveCount(2)
        ->and($skills->pluck('name')->sort()->values()->all())->toBe(['agent-browser', 'polish'])
        ->and($skills->firstWhere('name', 'agent-browser')->description)->toBe('Browser automation CLI for AI agents.');
});

it('returns empty when skills dir is missing', function () {
    config()->set('yak.skills_dir', $this->tmp . '/no-such-dir');
    expect(app(SkillManager::class)->listBundledSkills())->toHaveCount(0);
});

it('lists marketplaces from known_marketplaces.json', function () {
    File::put($this->tmp . '/known_marketplaces.json', json_encode([
        'acme' => [
            'source' => ['source' => 'github', 'repo' => 'acme/plugins'],
            'installLocation' => $this->tmp . '/marketplaces/acme',
            'lastUpdated' => '2026-04-12T10:00:00Z',
        ],
    ]));

    $list = app(SkillManager::class)->listMarketplaces();

    expect($list)->toHaveCount(1)
        ->and($list->first()->name)->toBe('acme')
        ->and($list->first()->source)->toBe('acme/plugins')
        ->and($list->first()->lastUpdated?->toIso8601String())->toBe('2026-04-12T10:00:00+00:00');
});

it('adds, removes, and refreshes marketplaces via the CLI', function () {
    Process::fake(['*' => Process::result(output: 'ok', exitCode: 0)]);

    app(SkillManager::class)->addMarketplace('github:acme/plugins');
    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins marketplace add')
        && str_contains($p->command, 'github:acme/plugins'));

    app(SkillManager::class)->removeMarketplace('acme');
    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins marketplace remove')
        && str_contains($p->command, 'acme'));

    app(SkillManager::class)->refreshMarketplaces();
    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins marketplace update'));
});

it('throws a ClaudeCliException when the CLI exits non-zero', function () {
    Process::fake(['*' => Process::result(errorOutput: 'boom', exitCode: 1)]);

    expect(fn () => app(SkillManager::class)->addMarketplace('bad'))
        ->toThrow(ClaudeCliException::class, 'boom');
});

it('installs a plugin scoped to a marketplace', function () {
    Process::fake(['*' => Process::result(output: 'ok', exitCode: 0)]);
    app(SkillManager::class)->install('code-review', 'claude-plugins-official');

    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins install')
        && str_contains($p->command, 'code-review@claude-plugins-official'));
});

it('installs a plugin without marketplace', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 0)]);
    app(SkillManager::class)->install('code-review');

    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins install')
        && str_contains($p->command, 'code-review')
        && ! str_contains($p->command, 'code-review@'));
});

it('installs a plugin from a URL or path', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 0)]);
    app(SkillManager::class)->installFromUrl('https://github.com/acme/plugin.git');

    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins install')
        && str_contains($p->command, 'github.com/acme/plugin.git'));
});

it('uninstalls, enables, disables, and updates', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 0)]);
    $manager = app(SkillManager::class);

    $manager->uninstall('code-review');
    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins uninstall')
        && str_contains($p->command, 'code-review'));

    $manager->enable('code-review');
    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins enable')
        && str_contains($p->command, 'code-review'));

    $manager->disable('code-review');
    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins disable')
        && str_contains($p->command, 'code-review'));

    $manager->update('code-review');
    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins update')
        && str_contains($p->command, 'code-review'));
});
