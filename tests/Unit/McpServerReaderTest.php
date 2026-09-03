<?php

use App\DataTransferObjects\McpServer;
use App\Exceptions\ClaudeCliException;
use App\Services\McpServerReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->tmp = sys_get_temp_dir() . '/yak-mcp-reader-' . uniqid();
    File::makeDirectory($this->tmp, recursive: true);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

const MCP_LIST_FIXTURE = <<<'TXT'
Checking MCP server health…

claude.ai Notion: https://mcp.notion.com/mcp - ✔ Connected
plugin:figma:figma: https://mcp.figma.com/mcp (HTTP) - ! Needs authentication
plugin:linear:linear: https://mcp.linear.app/mcp (HTTP) - ! Needs authentication
playwright: npx @playwright/mcp@latest - ✔ Connected
context7: npx -y @upstash/context7-mcp - ✔ Connected
stripe: https://mcp.stripe.com - ✘ Failed to connect
local-thing: node ./server.js - ⏸ Pending approval
TXT;

it('parses every mark, plugin names, and the HTTP hint from a realistic claude mcp list output', function () {
    Process::fake(['*mcp list*' => Process::result(output: MCP_LIST_FIXTURE)]);

    $servers = app(McpServerReader::class)->userServers();

    expect($servers)->toHaveCount(7);

    $byName = $servers->keyBy('name');

    /** @var McpServer $notion */
    $notion = $byName['claude.ai Notion'];
    expect($notion->status)->toBe('connected')
        ->and($notion->source)->toBe('user')
        ->and($notion->transport)->toBe('http')
        ->and($notion->canRemove)->toBeTrue()
        ->and($notion->canLogout)->toBeTrue();

    /** @var McpServer $figma */
    $figma = $byName['plugin:figma:figma'];
    expect($figma->status)->toBe('needs_auth')
        ->and($figma->source)->toBe('plugin')
        ->and($figma->pluginName)->toBe('figma')
        ->and($figma->displayName)->toBe('figma')
        ->and($figma->transport)->toBe('http')
        ->and($figma->canConnect)->toBeTrue()
        ->and($figma->canRemove)->toBeFalse();

    /** @var McpServer $playwright */
    $playwright = $byName['playwright'];
    expect($playwright->transport)->toBe('stdio')
        ->and($playwright->target)->toBe('npx @playwright/mcp@latest')
        ->and($playwright->status)->toBe('connected')
        ->and($playwright->canLogout)->toBeFalse();

    /** @var McpServer $stripe */
    $stripe = $byName['stripe'];
    expect($stripe->status)->toBe('failed')
        ->and($stripe->detail)->toBe('Failed to connect');

    /** @var McpServer $pending */
    $pending = $byName['local-thing'];
    expect($pending->status)->toBe('pending_approval');
});

it('ignores non-matching lines like the health-check banner and blanks', function () {
    Process::fake(['*mcp list*' => Process::result(output: "Checking MCP server health…\n\nplaywright: npx @playwright/mcp@latest - ✔ Connected\n")]);

    expect(app(McpServerReader::class)->userServers())->toHaveCount(1);
});

it('throws when claude mcp list exits non-zero', function () {
    Process::fake(['*mcp list*' => Process::result(output: '', errorOutput: 'not logged in', exitCode: 1)]);

    app(McpServerReader::class)->userServers();
})->throws(ClaudeCliException::class);

it('reads deploy servers from the configured JSON file', function () {
    $path = $this->tmp . '/mcp.json';
    File::put($path, json_encode([
        'mcpServers' => [
            'deploy-http' => ['type' => 'http', 'url' => 'https://mcp.example.com/mcp'],
            'deploy-stdio' => ['command' => 'npx', 'args' => ['-y', 'some-server']],
        ],
    ]));
    config()->set('yak.mcp_config_path', $path);

    $servers = app(McpServerReader::class)->deployServers();

    expect($servers)->toHaveCount(2);

    $byName = $servers->keyBy('name');

    /** @var McpServer $http */
    $http = $byName['deploy-http'];
    expect($http->status)->toBe('token')
        ->and($http->source)->toBe('deploy')
        ->and($http->transport)->toBe('http')
        ->and($http->target)->toBe('https://mcp.example.com/mcp')
        ->and($http->canConnect)->toBeFalse()
        ->and($http->canRemove)->toBeFalse();

    /** @var McpServer $stdio */
    $stdio = $byName['deploy-stdio'];
    expect($stdio->transport)->toBe('stdio')
        ->and($stdio->target)->toBe('npx -y some-server');
});

it('returns an empty collection when the deploy config path is unset', function () {
    config()->set('yak.mcp_config_path', null);

    expect(app(McpServerReader::class)->deployServers())->toHaveCount(0);
});

it('returns an empty collection when the deploy config file is missing', function () {
    config()->set('yak.mcp_config_path', $this->tmp . '/missing.json');

    expect(app(McpServerReader::class)->deployServers())->toHaveCount(0);
});

it('returns an empty collection when the deploy config file is invalid JSON', function () {
    $path = $this->tmp . '/broken.json';
    File::put($path, 'not json');
    config()->set('yak.mcp_config_path', $path);

    expect(app(McpServerReader::class)->deployServers())->toHaveCount(0);
});
