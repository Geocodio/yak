<?php

namespace App\Console\Commands;

use App\Models\Repository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('yak:approval-level {slug : The repository slug} {level : DEFCON level 1 to 5} {--mode=shadow : shadow or enforce}')]
#[Description("Set a repository's approval level and mode")]
class ApprovalLevelCommand extends Command
{
    public function handle(): int
    {
        $repository = Repository::query()->where('slug', $this->argument('slug'))->first();

        if ($repository === null) {
            $this->components->error('Repository not found.');

            return self::FAILURE;
        }

        try {
            $repository->setApproval((int) $this->argument('level'), (string) $this->option('mode'), null);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("{$repository->slug}: level {$repository->approvalLevel()}, mode {$repository->approvalMode()}.");

        return self::SUCCESS;
    }
}
