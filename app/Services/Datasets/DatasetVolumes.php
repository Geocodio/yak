<?php

namespace App\Services\Datasets;

use App\Exceptions\DatasetCommandFailed;
use Illuminate\Support\Facades\Process;

/**
 * The only place that builds `incus storage volume` and dataset device
 * commands. Arguments are always an argv array, never a shell string.
 */
class DatasetVolumes
{
    private const TIMEOUT = 120;

    private const CLONE_TIMEOUT = 300;

    public function createVolume(string $volume, ?int $sizeCapGb): void
    {
        $command = ['incus', 'storage', 'volume', 'create', $this->pool(), $volume];
        if ($sizeCapGb !== null) {
            $command[] = "size={$sizeCapGb}GiB";
        }

        $this->run('create volume', $command, tolerate: 'already exists');
    }

    public function snapshot(string $volume, string $version): void
    {
        $this->run('snapshot', ['incus', 'storage', 'volume', 'snapshot', 'create', $this->pool(), $volume, $version]);
    }

    public function deleteSnapshot(string $volume, string $version): void
    {
        $this->run('delete snapshot', ['incus', 'storage', 'volume', 'snapshot', 'delete', $this->pool(), $volume, $version], tolerate: 'not found');
    }

    public function deleteVolume(string $volume): void
    {
        $this->run('delete volume', ['incus', 'storage', 'volume', 'delete', $this->pool(), $volume], tolerate: 'not found');
    }

    public function clone(string $volume, string $version, string $clone): void
    {
        $pool = $this->pool();

        $this->run('clone', ['incus', 'storage', 'volume', 'copy', "{$pool}/{$volume}/{$version}", "{$pool}/{$clone}"], timeout: self::CLONE_TIMEOUT);
    }

    public function attach(string $container, string $volume, string $path, bool $readOnly = false): void
    {
        $command = ['incus', 'config', 'device', 'add', $container, 'data', 'disk', 'pool=' . $this->pool(), "source={$volume}", "path={$path}"];
        if ($readOnly) {
            $command[] = 'readonly=true';
        }

        $this->run('attach', $command);
    }

    public function detach(string $container): void
    {
        $this->run('detach', ['incus', 'config', 'device', 'remove', $container, 'data'], tolerate: 'not found');
    }

    public function volumeExists(string $volume): bool
    {
        return Process::timeout(self::TIMEOUT)->run(['incus', 'storage', 'volume', 'show', $this->pool(), $volume])->successful();
    }

    /**
     * Replace every configured S3 credential value with a placeholder.
     */
    public function scrub(string $text): string
    {
        $secrets = array_filter(DatasetSource::fromConfig()->secrets(), fn (string $secret): bool => $secret !== '');

        return str_replace($secrets, '[redacted]', $text);
    }

    private function pool(): string
    {
        return (string) config('yak.sandbox.incus_pool', 'default');
    }

    /**
     * @param  list<string>  $command
     */
    private function run(string $operation, array $command, ?string $tolerate = null, int $timeout = self::TIMEOUT): void
    {
        $result = Process::timeout($timeout)->run($command);

        if ($result->successful()) {
            return;
        }

        if ($tolerate !== null && str_contains($result->errorOutput(), $tolerate)) {
            return;
        }

        throw DatasetCommandFailed::for($operation, (int) $result->exitCode(), $this->scrub($result->errorOutput()));
    }
}
