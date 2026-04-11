<?php

namespace App\Console\Commands;

use App\Enums\TaskMode;
use App\Jobs\RunYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

#[Signature('yak:scan-ci {--repo=}')]
#[Description('Scan CI logs for flaky tests and create fix tasks')]
class ScanCiCommand extends Command
{
    public function handle(): int
    {
        /** @var string|null $repoSlug */
        $repoSlug = $this->option('repo');

        $repositories = $repoSlug !== null
            ? Repository::where('slug', $repoSlug)->where('is_active', true)->get()
            : Repository::where('is_active', true)->get();

        if ($repositories->isEmpty()) {
            $this->components->warn('No active repositories found.');

            return self::SUCCESS;
        }

        $tasksCreated = 0;

        foreach ($repositories as $repository) {
            $tasksCreated += $this->scanRepository($repository);
        }

        $this->components->info("Scan complete. Created {$tasksCreated} task(s).");

        return self::SUCCESS;
    }

    private function scanRepository(Repository $repository): int
    {
        try {
            $failures = $this->detectFlakyTests($repository);
        } catch (\Throwable $e) {
            Log::warning("Failed to scan CI for {$repository->slug}", [
                'error' => $e->getMessage(),
            ]);
            $this->components->error("Failed to scan {$repository->slug}: {$e->getMessage()}");

            return 0;
        }

        $tasksCreated = 0;

        foreach ($failures as $failure) {
            $existingTask = YakTask::where('repo', $repository->slug)
                ->where('description', 'like', "%{$failure['test']}%")
                ->whereNotIn('status', ['success', 'failed', 'expired'])
                ->first();

            if ($existingTask) {
                $this->components->warn("Skipping {$failure['test']} — task already exists.");

                continue;
            }

            $task = YakTask::create([
                'repo' => $repository->slug,
                'external_id' => 'ci-scan-'.Str::random(8),
                'mode' => TaskMode::Fix,
                'description' => "Fix flaky test: {$failure['test']}",
                'context' => $failure['output'],
                'source' => 'ci-scan',
            ]);

            RunYakJob::dispatch($task);
            $tasksCreated++;

            $this->components->info("Created task #{$task->id} for flaky test: {$failure['test']}");
        }

        return $tasksCreated;
    }

    /**
     * @return array<int, array{test: string, output: string}>
     */
    private function detectFlakyTests(Repository $repository): array
    {
        $result = Process::path($repository->path)
            ->timeout(120)
            ->run('php artisan test --compact 2>&1 || true');

        $output = $result->output();

        return $this->parseTestFailures($output);
    }

    /**
     * @return array<int, array{test: string, output: string}>
     */
    private function parseTestFailures(string $output): array
    {
        $failures = [];
        $lines = explode("\n", $output);

        $currentTest = null;
        $currentOutput = '';
        $inFailure = false;

        foreach ($lines as $line) {
            if (preg_match('/FAILED\s+(.+)/', $line, $matches)) {
                if ($currentTest !== null) {
                    $failures[] = [
                        'test' => $currentTest,
                        'output' => trim($currentOutput),
                    ];
                }

                $currentTest = trim($matches[1]);
                $currentOutput = $line."\n";
                $inFailure = true;
            } elseif ($inFailure) {
                $currentOutput .= $line."\n";
            }
        }

        if ($currentTest !== null) {
            $failures[] = [
                'test' => $currentTest,
                'output' => trim($currentOutput),
            ];
        }

        return $failures;
    }
}
