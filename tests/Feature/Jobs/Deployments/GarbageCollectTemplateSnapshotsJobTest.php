<?php

use App\Jobs\Deployments\GarbageCollectTemplateSnapshotsJob;
use App\Models\BranchDeployment;
use App\Models\Repository;
use Illuminate\Support\Facades\Process;

it('deletes snapshots not referenced by any repo or deployment', function () {
    Process::fake([
        'incus snapshot list *' => Process::result(
            exitCode: 0,
            output: implode("\n", [
                'yak-tpl-example-repo/ready-v1',
                'yak-tpl-example-repo/ready-v2',
                'yak-tpl-example-repo/ready-v3',
                'yak-tpl-example-repo/ready-v4',
            ]),
        ),
        'incus snapshot delete *' => Process::result(exitCode: 0),
    ]);

    $repo = Repository::factory()->create(['slug' => 'example-repo', 'current_template_version' => 4]);
    // Pin one deployment to v2
    BranchDeployment::factory()->for($repo)->running()->create(['template_version' => 2]);

    (new GarbageCollectTemplateSnapshotsJob)->handle();

    Process::assertRan(fn ($p) => str_contains($p->command, 'snapshot delete yak-tpl-example-repo/ready-v1'));
    Process::assertRan(fn ($p) => str_contains($p->command, 'snapshot delete yak-tpl-example-repo/ready-v3'));
    Process::assertDidntRun(fn ($p) => str_contains($p->command, 'snapshot delete yak-tpl-example-repo/ready-v2'));
    Process::assertDidntRun(fn ($p) => str_contains($p->command, 'snapshot delete yak-tpl-example-repo/ready-v4'));
});

it('never collects the current_template_version even with no deployments', function () {
    Process::fake([
        'incus snapshot list *' => Process::result(exitCode: 0, output: 'yak-tpl-foo/ready-v5'),
        'incus snapshot delete *' => Process::result(exitCode: 0),
    ]);

    Repository::factory()->create(['slug' => 'foo', 'current_template_version' => 5]);

    (new GarbageCollectTemplateSnapshotsJob)->handle();

    Process::assertDidntRun(fn ($p) => str_contains($p->command, 'snapshot delete'));
});
