<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config(['app.url' => 'https://yak.example.com/', 'yak.teleport.marketplace' => 'Acme/yak-fork']);
    $this->sandbox = sys_get_temp_dir() . '/yak-install-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->sandbox . '/fakes');
    File::ensureDirectoryExists($this->sandbox . '/home');
    $this->writeExecutable = function (string $path, string $contents): void {
        File::put($path, $contents);
        chmod($path, 0755);
    };
});

afterEach(function () {
    File::deleteDirectory($this->sandbox);
});

/**
 * Renders the script and runs it with a PATH that holds only the fakes plus system tools.
 */
function runInstallScript(string $sandbox, string $script): ProcessResult
{
    File::put($sandbox . '/install.sh', $script);

    return Process::env(['PATH' => $sandbox . '/fakes:/usr/bin:/bin', 'HOME' => $sandbox . '/home'])
        ->run(['/bin/sh', $sandbox . '/install.sh']);
}

it('serves a plain text shell script to guests', function () {
    $response = $this->get(route('install'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/plain');
    $response->assertSee('https://yak.example.com', false)
        ->assertSee('Acme/yak-fork', false)
        ->assertSee('yak login --url', false)
        ->assertDontSee('&#039;', false)
        ->assertDontSee('&quot;', false)
        ->assertDontSee('&amp;', false);
    expect($response->getContent())->toStartWith('#!/bin/sh');
});

it('renders a script that passes a shell syntax check', function () {
    File::put($this->sandbox . '/install.sh', $this->get(route('install'))->getContent());

    expect(Process::run(['/bin/sh', '-n', $this->sandbox . '/install.sh'])->successful())->toBeTrue();
});

it('shell-quotes configured values so they cannot inject commands', function () {
    config(['yak.teleport.marketplace' => "x'; touch {$this->sandbox}/pwned; '"]);

    $result = runInstallScript($this->sandbox, $this->get(route('install'))->getContent());

    expect(file_exists($this->sandbox . '/pwned'))->toBeFalse();
});

it('adds the marketplace, installs the plugin, links yak and logs in', function () {
    ($this->writeExecutable)($this->sandbox . '/fakes/claude', <<<'SH'
#!/bin/sh
echo "claude $*" >> "$HOME/calls.log"
if [ "$1 $2" = "plugin install" ]; then
  mkdir -p "$HOME/.claude/plugins/cache/yak/yak/0.1.0/bin"
  printf '#!/bin/sh\necho "yak $*" >> "$HOME/calls.log"\n' > "$HOME/.claude/plugins/cache/yak/yak/0.1.0/bin/yak"
  chmod +x "$HOME/.claude/plugins/cache/yak/yak/0.1.0/bin/yak"
fi
SH);

    $result = runInstallScript($this->sandbox, $this->get(route('install'))->getContent());

    expect($result->successful())->toBeTrue($result->output() . $result->errorOutput());
    expect(trim(file_get_contents($this->sandbox . '/home/calls.log')))->toBe(implode("\n", [
        'claude plugin marketplace add Acme/yak-fork',
        'claude plugin install yak@yak',
        'yak login --url https://yak.example.com',
    ]));
    expect(is_executable($this->sandbox . '/home/.local/bin/yak'))->toBeTrue();
    expect($result->output())->toContain('export PATH=');
});

it('keeps yak working after a plugin update moves the install directory', function () {
    ($this->writeExecutable)($this->sandbox . '/fakes/claude', "#!/bin/sh\nexit 0\n");
    $cache = $this->sandbox . '/home/.claude/plugins/cache/yak/yak';
    foreach (['0.1.0' => 'old', '0.2.0' => 'new'] as $version => $label) {
        File::ensureDirectoryExists("{$cache}/{$version}/bin");
        ($this->writeExecutable)("{$cache}/{$version}/bin/yak", "#!/bin/sh\necho {$label}\n");
    }
    touch("{$cache}/0.1.0", time() - 100);
    touch("{$cache}/0.1.0/.orphaned_at", time());
    touch("{$cache}/0.2.0", time() - 50);
    runInstallScript($this->sandbox, $this->get(route('install'))->getContent());

    $result = Process::env(['PATH' => '/usr/bin:/bin', 'HOME' => $this->sandbox . '/home'])->run([$this->sandbox . '/home/.local/bin/yak']);

    expect(trim($result->output()))->toBe('new');
});

it('stops before changing anything when claude is not installed', function () {
    $result = runInstallScript($this->sandbox, $this->get(route('install'))->getContent());

    expect($result->successful())->toBeFalse();
    expect($result->errorOutput() . $result->output())->toContain('Claude Code is not installed. Install it first: https://code.claude.com/docs/en/setup');
    expect(file_exists($this->sandbox . '/home/calls.log'))->toBeFalse();
    expect(file_exists($this->sandbox . '/home/.local'))->toBeFalse();
});

/**
 * Builds a plugin directory with the real launcher and a fake curl that serves a known binary.
 */
function launcherSandbox(string $sandbox, string $sumsMode): void
{
    $binary = "#!/bin/sh\necho downloaded \"\$@\"\n";
    File::ensureDirectoryExists($sandbox . '/plugin/bin');
    File::ensureDirectoryExists($sandbox . '/plugin/.claude-plugin');
    File::copy(base_path('plugin/bin/yak'), $sandbox . '/plugin/bin/yak');
    chmod($sandbox . '/plugin/bin/yak', 0755);
    File::put($sandbox . '/plugin/.claude-plugin/plugin.json', '{"name": "yak", "version": "9.9.9"}');
    File::put($sandbox . '/binary', $binary);
    $hash = $sumsMode === 'match' ? hash('sha256', $binary) : str_repeat('0', 64);
    $sums = '';
    foreach (['yak-darwin-arm64', 'yak-darwin-x86_64', 'yak-linux-x86_64'] as $name) {
        $sums .= "{$hash}  {$name}\n";
    }
    File::put($sandbox . '/sums', $sums);
    File::put($sandbox . '/fakes/curl', <<<'SH'
#!/bin/sh
out=""; url=""
while [ $# -gt 0 ]; do
  case $1 in -o) out=$2; shift 2 ;; -*) shift ;; *) url=$1; shift ;; esac
done
echo "$url" >> "$FAKE_SANDBOX/curl.log"
case $url in
  */SHA256SUMS) cp "$FAKE_SANDBOX/sums" "$out" ;;
  *) cp "$FAKE_SANDBOX/binary" "$out" ;;
esac
SH);
    chmod($sandbox . '/fakes/curl', 0755);
}

it('downloads the platform binary on first run, verifies it and runs it', function () {
    launcherSandbox($this->sandbox, 'match');

    $result = Process::env(['PATH' => $this->sandbox . '/fakes:/usr/bin:/bin', 'FAKE_SANDBOX' => $this->sandbox])
        ->run([$this->sandbox . '/plugin/bin/yak', 'hello']);

    expect($result->successful())->toBeTrue($result->errorOutput());
    expect(trim($result->output()))->toBe('downloaded hello');
    expect(file_get_contents($this->sandbox . '/curl.log'))
        ->toContain('https://github.com/Geocodio/yak/releases/download/cli-v9.9.9/yak-')
        ->toContain('/cli-v9.9.9/SHA256SUMS');
});

it('refuses a binary whose checksum does not match and leaves no file behind', function () {
    launcherSandbox($this->sandbox, 'mismatch');

    $result = Process::env(['PATH' => $this->sandbox . '/fakes:/usr/bin:/bin', 'FAKE_SANDBOX' => $this->sandbox])
        ->run([$this->sandbox . '/plugin/bin/yak', 'hello']);

    expect($result->exitCode())->toBe(1);
    expect($result->errorOutput())->toContain('checksum');
    expect(glob($this->sandbox . '/plugin/bin/*'))->toBe([$this->sandbox . '/plugin/bin/yak']);
    expect(glob($this->sandbox . '/plugin/bin/.yak*'))->toBe([]);
});
