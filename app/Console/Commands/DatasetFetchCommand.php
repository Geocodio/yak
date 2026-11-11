<?php

namespace App\Console\Commands;

use App\Exceptions\DatasetFetchRefused;
use App\Models\Repository;
use App\Services\Datasets\DatasetFetcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('yak:dataset-fetch {repository : Repository slug} {--dataset-version= : Version string}')]
#[Description('Fetch a repository dataset from the S3 source into its volume; runs inline for as long as the sync takes')]
class DatasetFetchCommand extends Command
{
    /**
     * Runs outside any queue worker because a large fetch outlives every worker timeout.
     */
    public function handle(DatasetFetcher $fetcher): int
    {
        $repository = Repository::where('slug', $this->argument('repository'))->first();

        if ($repository === null) {
            $this->error('Unknown repository.');

            return self::FAILURE;
        }

        $lock = Cache::lock('yak:dataset-fetch:' . $repository->id, (int) config('yak.datasets.fetch_timeout_minutes') * 60);

        if (! $lock->get()) {
            $this->error('A fetch for this repository is already running.');

            return self::FAILURE;
        }

        try {
            $version = $fetcher->fetch($repository, $this->option('dataset-version') ?: null);
            $this->info("Dataset version {$version->version} is {$version->status->value}.");

            return self::SUCCESS;
        } catch (DatasetFetchRefused $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
