<?php

namespace App\Services\Datasets;

use App\Enums\DatasetStatus;
use App\Enums\DatasetVersionStatus;
use App\Exceptions\DatasetFetchRefused;
use App\Models\Dataset;
use App\Models\DatasetVersion;
use App\Models\Repository;
use App\Services\Threads\Contracts\SandboxManager;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Fetches a dataset version from the S3 source into the repository's master
 * volume inside a one-shot data sandbox that alone holds the S3 credential,
 * then publishes a snapshot of it.
 *
 * The AWS CLI must be present in the base sandbox template and the data
 * sandbox needs network egress to the S3 endpoint; neither is set up here.
 */
class DatasetFetcher
{
    private const VOLUME_MOUNT = '/data/volume';

    private const QUERY_TIMEOUT = 60;

    public function __construct(
        private readonly DatasetVolumes $volumes,
        private readonly DatasetVersions $versions,
        private readonly SandboxManager $sandboxes,
    ) {}

    public function fetch(Repository $repository, ?string $version = null): DatasetVersion
    {
        $source = DatasetSource::fromConfig();
        $config = $this->refuseUnlessAllowed($repository, $source);
        $dataset = $this->upsertDataset($repository, $config);

        if ($version !== null) {
            $this->assertValidVersion($version);
            $existing = $this->readyVersion($dataset, $version);
            if ($existing !== null) {
                return $existing;
            }
        }

        $sandbox = $dataset->volume_name . '-fetch';
        $row = $version !== null ? $this->startVersion($dataset, $version) : null;

        try {
            $this->createDataSandbox($sandbox);
            $this->preflightCli($sandbox);
            $this->pushCredentials($sandbox, $source);

            if ($row === null) {
                $version = $this->resolveVersion($sandbox, $source, $config);
                $existing = $this->readyVersion($dataset, $version);
                if ($existing !== null) {
                    return $existing;
                }
                $row = $this->startVersion($dataset, $version);
            }

            $this->volumes->createVolume($dataset->volume_name, $dataset->size_cap_gb);
            $this->volumes->attach($sandbox, $dataset->volume_name, self::VOLUME_MOUNT);
            $this->sync($sandbox, $source, $config, $row->version);
            $this->versions->publish($row);

            return $row->refresh();
        } catch (Throwable $failure) {
            $this->recordFailure($dataset, $row, $failure);

            throw $failure;
        } finally {
            Process::timeout(300)->run(['incus', 'delete', '--force', $sandbox]);
        }
    }

    private function refuseUnlessAllowed(Repository $repository, DatasetSource $source): DatasetConfig
    {
        if (! $source->isConfigured()) {
            throw new DatasetFetchRefused('The dataset S3 source is not configured (yak.datasets.s3).');
        }

        $config = DatasetConfig::fromRepository($repository);
        if ($config === null) {
            throw new DatasetFetchRefused("Repository {$repository->slug} declares no data section.");
        }

        $existing = Dataset::where('repository_id', $repository->id)->first();
        if ($existing?->size_cap_gb !== null && $config->sizeGb !== null && $config->sizeGb > $existing->size_cap_gb) {
            throw new DatasetFetchRefused("Declared size {$config->sizeGb} GB is above the {$existing->size_cap_gb} GB cap.");
        }

        if ($this->sandboxes->poolUsedPercent() >= (int) config('yak.threads.pool_watermark_percent')) {
            throw new DatasetFetchRefused('The storage pool is at or above its watermark.');
        }

        if ($existing !== null && ! $this->versions->canStartFetch($existing)) {
            throw new DatasetFetchRefused('Two dataset versions already have live clones.');
        }

        return $config;
    }

    private function upsertDataset(Repository $repository, DatasetConfig $config): Dataset
    {
        return Dataset::updateOrCreate(['repository_id' => $repository->id], [
            'name' => $config->name,
            'source_prefix' => $config->prefix,
            'version_key' => $config->versionKey,
            'mount_path' => $config->mountPath,
            'declared_size_gb' => $config->sizeGb,
            'volume_name' => Dataset::volumeNameFor($repository),
        ]);
    }

    private function assertValidVersion(string $version): void
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $version) !== 1) {
            throw new DatasetFetchRefused('The dataset version string is not valid.');
        }
    }

    private function readyVersion(Dataset $dataset, string $version): ?DatasetVersion
    {
        return $dataset->versions()->where('version', $version)->where('status', DatasetVersionStatus::Ready)->first();
    }

    private function startVersion(Dataset $dataset, string $version): DatasetVersion
    {
        $dataset->update(['status' => DatasetStatus::Fetching]);

        return DatasetVersion::updateOrCreate(
            ['dataset_id' => $dataset->id, 'version' => $version],
            ['status' => DatasetVersionStatus::Fetching],
        );
    }

    private function createDataSandbox(string $sandbox): void
    {
        $template = config('yak.sandbox.base_template') . '/' . config('yak.sandbox.snapshot_name');

        $this->mustRun('copy data sandbox', ['incus', 'copy', $template, $sandbox], 600);
        $this->mustRun('start data sandbox', ['incus', 'start', $sandbox], 120);

        $timeout = (int) config('yak.sandbox.ready_timeout_seconds', 60);
        $probe = null;
        for ($attempt = 0; $attempt < $timeout; $attempt++) {
            $probe = Process::timeout(10)->run(['incus', 'exec', $sandbox, '--', 'true']);
            if ($probe->successful()) {
                return;
            }
            Sleep::for(1)->second();
        }

        throw new DatasetFetchRefused("The data sandbox did not become ready within {$timeout}s.");
    }

    private function preflightCli(string $sandbox): void
    {
        $cli = (string) config('yak.datasets.cli');

        if (Process::timeout(self::QUERY_TIMEOUT)->run(['incus', 'exec', $sandbox, '--', $cli, '--version'])->failed()) {
            throw new DatasetFetchRefused("The base sandbox template has no `{$cli}`; install it in the template.");
        }
    }

    private function pushCredentials(string $sandbox, DatasetSource $source): void
    {
        $this->pushFile($sandbox, '/root/.aws/credentials', $source->credentialFile(), 'chmod 600 /root/.aws/credentials');

        if ($source->usesPathStyle()) {
            $this->pushFile($sandbox, '/root/.aws/config', "[default]\ns3 =\n    addressing_style = path\n");
        }
    }

    private function pushFile(string $sandbox, string $path, string $content, string $after = 'true'): void
    {
        $result = Process::timeout(self::QUERY_TIMEOUT)->input($content)->run([
            'incus', 'exec', $sandbox, '--', 'sh', '-c', "mkdir -p /root/.aws && cat > {$path} && {$after}",
        ]);

        $this->assertSucceeded('push aws settings', $result);
    }

    private function resolveVersion(string $sandbox, DatasetSource $source, DatasetConfig $config): string
    {
        $result = Process::timeout(self::QUERY_TIMEOUT)->run([
            'incus', 'exec', $sandbox, '--', (string) config('yak.datasets.cli'), 's3', 'cp',
            "s3://{$source->bucket()}/{$config->versionKey}", '-', '--endpoint-url', $source->endpoint(), '--region', $source->region(),
        ]);
        $this->assertSucceeded('read version key', $result);

        $version = trim(strtok($result->output(), "\n") ?: '');
        $this->assertValidVersion($version);

        return $version;
    }

    private function sync(string $sandbox, DatasetSource $source, DatasetConfig $config, string $version): void
    {
        $result = Process::timeout((int) config('yak.datasets.fetch_timeout_minutes') * 60)->run([
            'incus', 'exec', $sandbox, '--', (string) config('yak.datasets.cli'), 's3', 'sync',
            "s3://{$source->bucket()}/{$config->prefix}/{$version}/", self::VOLUME_MOUNT . '/', '--delete',
            '--endpoint-url', $source->endpoint(), '--region', $source->region(), '--only-show-errors',
        ]);

        $this->assertSucceeded('sync', $result);
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRun(string $operation, array $command, int $timeout): void
    {
        $this->assertSucceeded($operation, Process::timeout($timeout)->run($command));
    }

    private function assertSucceeded(string $operation, ProcessResult $result): void
    {
        if ($result->failed()) {
            throw new DatasetFetchRefused("Dataset {$operation} failed (exit {$result->exitCode()}): " . mb_substr(trim($this->volumes->scrub($result->errorOutput())), 0, 500));
        }
    }

    private function recordFailure(Dataset $dataset, ?DatasetVersion $row, Throwable $failure): void
    {
        $row?->update(['status' => DatasetVersionStatus::Failed]);

        $dataset->update([
            'status' => $dataset->current_version_id === null ? DatasetStatus::Failed : DatasetStatus::Ready,
            'last_error' => mb_substr($this->volumes->scrub($failure->getMessage()), 0, 1000),
        ]);
    }
}
