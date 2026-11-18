<?php

namespace App\Console\Commands;

use App\Channels\Linear\IssueImages;
use App\Models\ThreadTurnMessage;
use App\Models\YakTask;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:backfill-linear-images {--dry-run : List the tasks without changing them}')]
#[Description('Store the images of existing Linear tasks as attachments and remove the raw image markup from their text')]
class BackfillLinearImagesCommand extends Command
{
    public function handle(IssueImages $images): int
    {
        $count = 0;

        YakTask::query()
            ->withoutGlobalScopes()
            ->where('source', 'linear')
            ->where(fn ($query) => $query->where('description', 'like', '%<linear-image>%')->orWhere('description', 'like', '%](https://uploads.linear.app/%'))
            ->chunkById(50, function ($tasks) use ($images, &$count): void {
                foreach ($tasks as $task) {
                    $count += $this->backfill($task, $images) ? 1 : 0;
                }
            });

        $this->components->info(($this->option('dry-run') ? 'Would update' : 'Updated') . " {$count} Linear tasks.");

        return self::SUCCESS;
    }

    private function backfill(YakTask $task, IssueImages $images): bool
    {
        $original = (string) $task->description;
        $parsed = $images->extract($original);

        if ($parsed['images'] === [] && $parsed['text'] === $original) {
            return false;
        }

        $this->line("Task {$task->id}: " . count($parsed['images']) . ' images');

        if ($this->option('dry-run')) {
            return true;
        }

        $context = json_decode((string) $task->context, true);
        $task->update([
            'description' => $parsed['text'],
            'context' => json_encode([...(is_array($context) ? $context : []), 'linear_images' => $parsed['images']]),
        ]);
        $images->storeForTask($task);

        ThreadTurnMessage::query()
            ->whereHas('taskRun', fn ($runs) => $runs->where('yak_task_id', $task->id))
            ->whereNotNull('text')
            ->get()
            ->each(function (ThreadTurnMessage $message) use ($task, $original, $images): void {
                $text = $message->text === $original ? (string) $task->description : $images->extract((string) $message->text)['text'];

                if ($text !== $message->text) {
                    $message->update(['text' => $text]);
                }
            });

        return true;
    }
}
