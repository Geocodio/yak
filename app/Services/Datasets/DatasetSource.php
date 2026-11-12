<?php

namespace App\Services\Datasets;

/**
 * The S3-compatible store datasets are fetched from, read from
 * `yak.datasets.s3`. Only the one-shot data sandbox ever receives the
 * credential file; everything else uses the non-secret accessors.
 */
final class DatasetSource
{
    private function __construct(
        private readonly string $endpoint,
        private readonly string $region,
        private readonly string $bucket,
        private readonly string $accessKey,
        private readonly string $secret,
        private readonly bool $usesPathStyle,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            endpoint: (string) config('yak.datasets.s3.endpoint'),
            region: (string) config('yak.datasets.s3.region'),
            bucket: (string) config('yak.datasets.s3.bucket'),
            accessKey: (string) config('yak.datasets.s3.access_key'),
            secret: (string) config('yak.datasets.s3.secret'),
            usesPathStyle: (bool) config('yak.datasets.s3.use_path_style_endpoint'),
        );
    }

    public function isConfigured(): bool
    {
        return $this->endpoint !== '' && $this->bucket !== '' && $this->accessKey !== '' && $this->secret !== '';
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function region(): string
    {
        return $this->region;
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    public function usesPathStyle(): bool
    {
        return $this->usesPathStyle;
    }

    /**
     * The aws credentials ini for the data sandbox; built only here.
     */
    public function credentialFile(): string
    {
        return "[default]\naws_access_key_id = {$this->accessKey}\naws_secret_access_key = {$this->secret}\n";
    }

    /**
     * Every value that must be scrubbed from command output and logs.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        return [$this->accessKey, $this->secret];
    }
}
