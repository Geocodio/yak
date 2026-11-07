<?php

namespace App\Channels\Slack;

use App\Models\BranchDeployment;
use App\Models\PreviewRepair;
use App\Models\Repository;

/**
 * Tells people about failed previews and the repairs Yak opens for them:
 * direct messages to the affected authors and posts in the feed channel.
 */
class PreviewRepairMessenger
{
    public function repairOpened(PreviewRepair $previewRepair): void {}

    public function verdictReached(PreviewRepair $previewRepair): void {}

    public function reminder(PreviewRepair $previewRepair): void {}

    public function capReached(Repository $repository): void {}

    public function repairPreviewFailed(BranchDeployment $deployment): void {}

    public function rebuilt(PreviewRepair $previewRepair): void {}
}
