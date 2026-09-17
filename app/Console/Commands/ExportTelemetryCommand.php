<?php

namespace App\Console\Commands;

use App\Models\TaskRun;
use App\Models\TelemetryEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dump task_runs or telemetry_events as CSV or JSON lines for analysis
 * outside the dashboard (a notebook, DuckDB, a spreadsheet) while the
 * Analytics page is still finding out which cuts matter.
 */
#[Signature('yak:telemetry:export {table=runs : runs or events} {--since= : ISO date, defaults to 30 days ago} {--until= : ISO date, defaults to now} {--format=csv : csv or jsonl} {--output= : File path, defaults to stdout}')]
#[Description('Export task runs or telemetry events as CSV or JSON lines')]
class ExportTelemetryCommand extends Command
{
    public function handle(): int
    {
        $table = (string) $this->argument('table');
        $format = (string) $this->option('format');

        if (! in_array($table, ['runs', 'events'], true)) {
            $this->components->error("Unknown table '{$table}'. Use runs or events.");

            return self::FAILURE;
        }

        if (! in_array($format, ['csv', 'jsonl'], true)) {
            $this->components->error("Unknown format '{$format}'. Use csv or jsonl.");

            return self::FAILURE;
        }

        $since = $this->option('since') !== null ? now()->parse((string) $this->option('since')) : now()->subDays(30);
        $until = $this->option('until') !== null ? now()->parse((string) $this->option('until')) : now();
        $timeColumn = $table === 'runs' ? 'started_at' : 'occurred_at';

        /** @var Builder<TaskRun>|Builder<TelemetryEvent> $query */
        $query = ($table === 'runs' ? TaskRun::query() : TelemetryEvent::query())
            ->whereBetween($timeColumn, [$since, $until])
            ->orderBy($timeColumn);

        $output = $this->option('output');
        $handle = $output !== null ? fopen((string) $output, 'w') : fopen('php://stdout', 'w');

        if ($handle === false) {
            $this->components->error('Could not open output for writing.');

            return self::FAILURE;
        }

        $count = 0;
        $headerWritten = false;

        $query->chunkById(500, function ($rows) use ($handle, $format, &$count, &$headerWritten): void {
            foreach ($rows as $row) {
                $data = $this->flatten($row->toArray());

                if ($format === 'jsonl') {
                    fwrite($handle, json_encode($data, JSON_THROW_ON_ERROR) . "\n");
                } else {
                    if (! $headerWritten) {
                        fputcsv($handle, array_keys($data));
                        $headerWritten = true;
                    }
                    fputcsv($handle, array_values($data));
                }

                $count++;
            }
        });

        fclose($handle);

        if ($output !== null) {
            $this->components->info("Exported {$count} {$table} row(s) to {$output}.");
        }

        return self::SUCCESS;
    }

    /**
     * Nested JSON columns become JSON strings so a CSV row stays flat.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, scalar|null>
     */
    private function flatten(array $row): array
    {
        $flat = [];

        foreach ($row as $key => $value) {
            $flat[$key] = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
        }

        return $flat;
    }
}
