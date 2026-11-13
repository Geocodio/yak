<?php

use Illuminate\Support\Facades\Process;

function splash(array $env, string $title = 'Fix the login redirect'): string
{
    $file = tempnam(sys_get_temp_dir(), 'yak-thread');
    file_put_contents($file, "TITLE={$title}\nPUBLIC_ID=k7m2x9qd\nREPO=acme/widgets\nBRANCH=yak/fix-login\nDOCS_URL=https://docs.example/ssh/\n");

    $result = Process::env($env + ['YAK_THREAD_ENV' => $file, 'PATH' => getenv('PATH')])
        ->run(base_path('ansible/roles/incus/files/yak-motd.sh'));
    unlink($file);

    return $result->output();
}

it('renders the thread details in under 20 lines', function () {
    $output = splash(['TERM' => 'dumb']);

    expect($output)->toContain('k7m2x9qd')->toContain('acme/widgets')->toContain('yak/fix-login')->toContain('/workspace')
        ->toContain('https://docs.example/ssh/')
        ->and(substr_count($output, "\n"))->toBeLessThan(20)
        ->and($output)->not->toContain("\e");
});

it('emits no escape codes when NO_COLOR is set and some when colour is available', function () {
    expect(splash(['TERM' => 'xterm-256color', 'NO_COLOR' => '1']))->not->toContain("\e")
        ->and(splash(['TERM' => 'xterm-256color', 'COLORTERM' => '']))->toContain("\e[38;5;166m")
        ->and(splash(['TERM' => 'xterm-256color', 'COLORTERM' => 'truecolor']))->toContain("\e[38;2;196;100;58m");
});

it('truncates a long title and strips escape characters from it', function () {
    $output = splash(['TERM' => 'dumb'], str_repeat('long ', 40) . "\e[31m");

    expect($output)->toContain('...')->not->toContain("\e")
        ->and(collect(explode("\n", $output))->max(fn ($line) => strlen($line)))->toBeLessThan(80);
});
