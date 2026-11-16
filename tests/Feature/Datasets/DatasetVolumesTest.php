<?php

use App\Exceptions\DatasetCommandFailed;
use App\Services\Datasets\DatasetVolumes;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config(['yak.sandbox.incus_pool' => 'tank', 'yak.datasets.s3.secret' => 'sekrit-value']);
});

it('builds the exact argv for each operation', function (string $method, array $arguments, string $expected) {
    Process::fake();

    app(DatasetVolumes::class)->{$method}(...$arguments);

    Process::assertRan(fn ($process) => implode(' ', $process->command) === $expected);
})->with([
    'create sized' => ['createVolume', ['yak-data-a', 700], 'incus storage volume create tank yak-data-a size=700GiB'],
    'create unsized' => ['createVolume', ['yak-data-a', null], 'incus storage volume create tank yak-data-a'],
    'snapshot' => ['snapshot', ['yak-data-a', 'v1'], 'incus storage volume snapshot create tank yak-data-a v1'],
    'delete snapshot' => ['deleteSnapshot', ['yak-data-a', 'v1'], 'incus storage volume snapshot delete tank yak-data-a v1'],
    'delete volume' => ['deleteVolume', ['yak-data-a'], 'incus storage volume delete tank yak-data-a'],
    'clone' => ['clone', ['yak-data-a', 'v1', 'yak-clone-thread-3'], 'incus storage volume copy tank/yak-data-a/v1 tank/yak-clone-thread-3'],
    'attach' => ['attach', ['c1', 'vol', '/data'], 'incus config device add c1 data disk pool=tank source=vol path=/data'],
    'attach read only' => ['attach', ['c1', 'vol', '/data', true], 'incus config device add c1 data disk pool=tank source=vol path=/data readonly=true'],
    'detach' => ['detach', ['c1'], 'incus config device remove c1 data'],
]);

it('tolerates already exists and not found', function (string $method, array $arguments, string $stderr) {
    Process::fake(['*' => Process::result('', $stderr, 1)]);

    app(DatasetVolumes::class)->{$method}(...$arguments);

    expect(true)->toBeTrue();
})->with([
    ['createVolume', ['v', null], 'Error: Volume already exists'],
    ['deleteSnapshot', ['v', 'v1'], 'Error: Storage volume snapshot not found'],
    ['deleteVolume', ['v'], 'Error: Storage volume not found'],
    ['detach', ['c1'], 'Error: Device not found'],
]);

it('throws on other failures', function () {
    Process::fake(['*' => Process::result('', 'Error: pool full', 1)]);

    app(DatasetVolumes::class)->snapshot('v', 'v1');
})->throws(DatasetCommandFailed::class, 'pool full');

it('reports whether a volume exists', function () {
    Process::fake([
        "*'show' 'tank' 'present'" => Process::result('name: present'),
        '*' => Process::result('', 'not found', 1),
    ]);

    expect(app(DatasetVolumes::class)->volumeExists('present'))->toBeTrue()
        ->and(app(DatasetVolumes::class)->volumeExists('absent'))->toBeFalse();
});

it('scrubs the secret from a failed clone message', function () {
    Process::fake(['*' => Process::result('', 'denied for sekrit-value', 1)]);

    try {
        app(DatasetVolumes::class)->clone('v', 'v1', 'c');
        $this->fail('expected a failure');
    } catch (DatasetCommandFailed $failure) {
        expect($failure->getMessage())->toContain('[redacted]')->not->toContain('sekrit-value');
    }
});
