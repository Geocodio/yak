<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $this->deployConfig = sys_get_temp_dir() . '/yak-mcp-browser-' . uniqid() . '.json';
    File::put($this->deployConfig, json_encode([
        'mcpServers' => [
            'sentry-alerts' => [
                'type' => 'http',
                'url' => 'https://mcp.sentry.dev/deploy',
            ],
        ],
    ]));

    config()->set('yak.mcp_config_path', $this->deployConfig);
    config()->set('yak.ssh_host', 'geocodio.yak.build');

    $mcpListOutput = implode("\n", [
        'linear: https://mcp.linear.app/sse (SSE) - ✔ Connected',
        'sentry: https://mcp.sentry.dev/mcp (HTTP) - ! Needs authentication',
        'plugin:figma-tools:figma: npx -y figma-mcp - ✔ Connected',
    ]);

    Process::fake([
        '*mcp list*' => Process::result(output: $mcpListOutput, exitCode: 0),
        '*' => Process::result(output: 'ok', exitCode: 0),
    ]);

    Queue::fake();
});

afterEach(function () {
    File::delete($this->deployConfig);
});

test('MCP servers page renders servers and starts a login for one that needs auth', function () {
    $page = visit(route('settings.mcp'));

    $page->assertNoJavaScriptErrors();
    $page->assertSee('MCP servers');

    $page->waitForText('sentry');
    $page->assertSee('linear');
    $page->assertSee('sentry-alerts');
    $page->assertSee('figma');

    $page->click('[data-testid="mcp-connect-sentry"]');

    $page->waitForText('Asking sentry for an authorization link');
    $page->assertVisible('[data-testid="mcp-login-panel-sentry"]');

    $page->assertNoJavaScriptErrors();
});
