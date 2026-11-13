<?php

use App\Support\PublicId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the public_id that URLs use in place of the integer primary key. Existing
 * rows are backfilled in chunks before the column gets its unique index. The column stays nullable so MariaDB and SQLite alter the table in place;
 * the HasPublicId trait fills it on every insert.
 */
return new class extends Migration
{
    private const TABLES = ['threads', 'tasks', 'branch_deployments'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->char('public_id', PublicId::LENGTH)->nullable();
            });

            $this->backfill($table);

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unique('public_id');
            });
        }

        $this->rewriteChildCardEvents();
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropUnique(['public_id']);
                $blueprint->dropColumn('public_id');
            });
        }
    }

    /**
     * A sub-thread card event names its sub-thread; the page builds URLs from it, so it carries the public id.
     */
    private function rewriteChildCardEvents(): void
    {
        DB::table('thread_events')->where('type', 'child')->select(['id', 'payload'])->orderBy('id')->chunkById(500, function ($events): void {
            foreach ($events as $event) {
                $payload = json_decode((string) $event->payload, true);

                if (! is_array($payload) || ! isset($payload['childThreadId']) || ! is_int($payload['childThreadId'])) {
                    continue;
                }

                $publicId = DB::table('threads')->where('id', $payload['childThreadId'])->value('public_id');

                if ($publicId !== null) {
                    $payload['childThreadId'] = $publicId;
                    DB::table('thread_events')->where('id', $event->id)->update(['payload' => json_encode($payload)]);
                }
            }
        });
    }

    private function backfill(string $table): void
    {
        $taken = [];

        DB::table($table)->whereNull('public_id')->select('id')->orderBy('id')->chunkById(500, function ($rows) use ($table, &$taken): void {
            foreach ($rows as $row) {
                do {
                    $publicId = PublicId::generate();
                } while (isset($taken[$publicId]) || DB::table($table)->where('public_id', $publicId)->exists());

                $taken[$publicId] = true;

                DB::table($table)->where('id', $row->id)->update(['public_id' => $publicId]);
            }
        });
    }
};
