<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flaky_test_claims', function (Blueprint $table) {
            $table->id();
            $table->string('repo');
            $table->string('test_class');
            $table->foreignId('yak_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('skipped_pr_url')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Deliberately not unique: whether a claim suppresses a new task
            // is a liveness question answered by FlakyTestClaim::live(), not
            // by the row existing. A test Yak failed to fix must be
            // claimable again.
            $table->index(['repo', 'test_class']);
        });

        $this->backfillExistingClaims();
    }

    public function down(): void
    {
        Schema::dropIfExists('flaky_test_claims');
    }

    /**
     * Existing flaky-test tasks predate this table, so without a backfill the
     * first scan after deploy re-tasks every test that already has a Yak PR
     * in flight.
     */
    private function backfillExistingClaims(): void
    {
        $tasks = DB::table('tasks')
            ->select('id', 'repo', 'context')
            ->where('source', 'flaky-test')
            ->get();

        $rows = [];

        foreach ($tasks as $task) {
            if (! is_string($task->context) || $task->context === '') {
                continue;
            }

            $context = json_decode($task->context, true);

            if (! is_array($context) || ! isset($context['test_name']) || ! is_string($context['test_name'])) {
                continue;
            }

            $testClass = $this->deriveTestClass($context['test_name']);

            if ($testClass === '') {
                continue;
            }

            $rows[] = [
                'repo' => $task->repo,
                'test_class' => $testClass,
                'yak_task_id' => $task->id,
                'created_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('flaky_test_claims')->insert($chunk);
        }
    }

    /**
     * Mirrors CIBuildFailure::normalizeTestClass. Inlined rather than called
     * so this migration keeps working if that class moves.
     */
    private function deriveTestClass(string $testName): string
    {
        $name = trim($testName);

        if (str_contains($name, ' > ')) {
            $name = substr($name, 0, strpos($name, ' > '));
        }

        $name = (string) preg_replace('/\x{2026}.*$/u', '', $name);

        return trim(rtrim(trim($name), '\\/ '));
    }
};
