<?php

use App\Jobs\McpLoginJob;
use App\Models\User;
use App\Support\McpLoginSession;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

const CONTROLLER_MCP_LIST_FIXTURE = <<<'TXT'
Checking MCP server health…

linear: https://mcp.linear.app/mcp (HTTP) - ✔ Connected
plugin:figma:figma: https://mcp.figma.com/mcp (HTTP) - ! Needs authentication
TXT;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Process::fake([
        '*mcp list*' => Process::result(output: CONTROLLER_MCP_LIST_FIXTURE),
        '*' => Process::result(),
    ]);
    config()->set('yak.mcp_config_path', null);
});

/**
 * Requests the deferred `servers` prop via an Inertia partial reload, the
 * way the frontend's `<Deferred data="servers">` follow-up would.
 */
function requestMcpServers(?string $only = 'servers,checkedAgo')
{
    $version = test()->get(route('settings.mcp'), ['X-Inertia' => 'true'])->headers->get('X-Inertia-Version');

    $headers = [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'Settings/McpServers',
    ];

    if ($only !== null) {
        $headers['X-Inertia-Partial-Data'] = $only;
    }

    return test()->get(route('settings.mcp'), $headers);
}

it('renders the page with deferred servers and eager login sessions', function () {
    $this->get(route('settings.mcp'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/McpServers')
            ->where('checkedAgo', null)
            ->has('loginSessions')
            ->has('docsUrl')
            ->missing('servers'));
});

it('resolves servers (deploy + user + plugin) and checkedAgo on the deferred partial request', function () {
    $response = requestMcpServers();

    $response->assertJsonPath('props.checkedAgo', 'just now');

    $names = collect($response->json('props.servers'))->pluck('name')->all();
    expect($names)->toContain('linear')->toContain('plugin:figma:figma');

    $byName = collect($response->json('props.servers'))->keyBy('name');
    expect($byName['linear']['status'])->toBe('connected')
        ->and($byName['linear']['source'])->toBe('user')
        ->and($byName['plugin:figma:figma']['source'])->toBe('plugin')
        ->and($byName['plugin:figma:figma']['status'])->toBe('needs_auth')
        ->and($byName['plugin:figma:figma']['canConnect'])->toBeTrue();
});

it('includes deploy-config servers alongside user and plugin servers', function () {
    $tmp = sys_get_temp_dir() . '/yak-mcp-controller-' . uniqid();
    File::makeDirectory($tmp, recursive: true);
    File::put($tmp . '/mcp.json', json_encode([
        'mcpServers' => ['deploy-one' => ['type' => 'http', 'url' => 'https://mcp.example.com']],
    ]));
    config()->set('yak.mcp_config_path', $tmp . '/mcp.json');

    $byName = collect(requestMcpServers()->json('props.servers'))->keyBy('name');

    expect($byName['deploy-one']['status'])->toBe('token')
        ->and($byName['deploy-one']['source'])->toBe('deploy')
        ->and($byName['deploy-one']['canRemove'])->toBeFalse();

    File::deleteDirectory($tmp);
});

it('flashes an error and omits user/plugin servers when claude mcp list fails', function () {
    Process::fake(['*mcp list*' => Process::result(output: '', errorOutput: 'not logged in', exitCode: 1)]);

    $response = requestMcpServers();

    expect($response->json('props.servers'))->toBe([]);
});

it('adds a server via the CLI and flashes success', function () {
    $this->post(route('settings.mcp.store'), [
        'name' => 'my-server',
        'transport' => 'http',
        'target' => 'https://mcp.example.com/mcp',
        'headers' => "Authorization: Bearer abc123\n",
    ])->assertRedirect();

    Process::assertRan(fn ($process) => str_contains($process->command, 'mcp add --scope user --transport http')
        && str_contains($process->command, "-H 'Authorization: Bearer abc123'")
        && str_contains($process->command, "'my-server'")
        && str_contains($process->command, "'https://mcp.example.com/mcp'"));
});

it('splits a stdio target into command and args after --', function () {
    $this->post(route('settings.mcp.store'), [
        'name' => 'local',
        'transport' => 'stdio',
        'target' => 'npx -y some-server',
    ])->assertRedirect();

    Process::assertRan(fn ($process) => str_contains($process->command, "-- 'npx' '-y' 'some-server'"));
});

it('rejects a store request with an invalid server name', function () {
    $this->post(route('settings.mcp.store'), [
        'name' => 'bad name!',
        'transport' => 'http',
        'target' => 'https://mcp.example.com',
    ])->assertSessionHasErrors('name');
});

it('rejects a store request with a non-url target for an http transport', function () {
    $this->post(route('settings.mcp.store'), [
        'name' => 'ok-name',
        'transport' => 'http',
        'target' => 'not a url',
    ])->assertSessionHasErrors('target');
});

it('refuses to remove a deploy-config server', function () {
    $tmp = sys_get_temp_dir() . '/yak-mcp-controller-' . uniqid();
    File::makeDirectory($tmp, recursive: true);
    File::put($tmp . '/mcp.json', json_encode([
        'mcpServers' => ['deploy-one' => ['type' => 'http', 'url' => 'https://mcp.example.com']],
    ]));
    config()->set('yak.mcp_config_path', $tmp . '/mcp.json');

    $this->delete(route('settings.mcp.destroy', ['name' => 'deploy-one']))
        ->assertRedirect()
        ->assertSessionHas('error');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'mcp remove'));

    File::deleteDirectory($tmp);
});

it('refuses to remove a plugin server', function () {
    $this->delete(route('settings.mcp.destroy', ['name' => 'plugin:figma:figma']))
        ->assertRedirect()
        ->assertSessionHas('error');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'mcp remove'));
});

it('removes a user server, ignoring logout failure', function () {
    Process::fake([
        '*mcp logout*' => Process::result(exitCode: 1, errorOutput: 'not connected'),
        '*mcp remove*' => Process::result(),
        '*mcp list*' => Process::result(output: CONTROLLER_MCP_LIST_FIXTURE),
    ]);

    $this->delete(route('settings.mcp.destroy', ['name' => 'linear']))
        ->assertRedirect()
        ->assertSessionHas('success');

    Process::assertRan(fn ($process) => str_contains($process->command, "mcp remove --scope user 'linear'"));
});

it('logs out of a server', function () {
    Process::fake(['*mcp logout*' => Process::result()]);

    $this->post(route('settings.mcp.logout', ['name' => 'linear']))
        ->assertRedirect()
        ->assertSessionHas('success');

    Process::assertRan(fn ($process) => str_contains($process->command, "mcp logout 'linear'"));
});

it('starts a login session and dispatches the login job', function () {
    Queue::fake();

    $this->post(route('settings.mcp.login.start', ['name' => 'plugin:figma:figma']))
        ->assertRedirect();

    Queue::assertPushed(McpLoginJob::class, fn (McpLoginJob $job) => $job->server === 'plugin:figma:figma');

    $session = McpLoginSession::find('plugin:figma:figma');
    expect($session)->not->toBeNull()
        ->and($session->status)->toBe('starting');
});

it('refuses to start a second login while one is already in progress', function () {
    Queue::fake();

    McpLoginSession::start('linear');

    $this->post(route('settings.mcp.login.start', ['name' => 'linear']))
        ->assertRedirect()
        ->assertSessionHas('error');

    Queue::assertNotPushed(McpLoginJob::class);
});

it('validates and applies a redirect url, moving the session to finishing', function () {
    $session = McpLoginSession::start('linear');
    $session->status = 'awaiting_redirect';
    $session->authorizationUrl = 'https://mcp.linear.app/authorize';
    $session->save();

    $this->post(route('settings.mcp.login.redirect', ['name' => 'linear']), [
        'redirectUrl' => 'http://localhost:57772/callback?code=abc',
    ])->assertRedirect();

    $updated = McpLoginSession::find('linear');
    expect($updated->status)->toBe('finishing')
        ->and($updated->redirectUrl)->toBe('http://localhost:57772/callback?code=abc');
});

it('rejects a redirect url that is not a localhost address', function () {
    $session = McpLoginSession::start('linear');
    $session->status = 'awaiting_redirect';
    $session->save();

    $this->post(route('settings.mcp.login.redirect', ['name' => 'linear']), [
        'redirectUrl' => 'https://evil.example.com/callback',
    ])->assertRedirect()->assertSessionHas('error');

    expect(McpLoginSession::find('linear')->status)->toBe('awaiting_redirect');
});

it('cancels an in-progress login', function () {
    McpLoginSession::start('linear');

    $this->delete(route('settings.mcp.login.cancel', ['name' => 'linear']))
        ->assertRedirect();

    expect(McpLoginSession::find('linear')->status)->toBe('cancelled');
});

it('requires authentication', function () {
    auth()->logout();

    $this->get(route('settings.mcp'))->assertRedirect(route('login'));
});
