<?php

namespace App\Support;

use App\Enums\DeploymentStatus;

final class DeploymentPresentation
{
    public static function label(DeploymentStatus $status): string
    {
        return match ($status) {
            DeploymentStatus::Pending => 'Pending',
            DeploymentStatus::Starting => 'Starting',
            DeploymentStatus::Running => 'Running',
            DeploymentStatus::Hibernated => 'Hibernated',
            DeploymentStatus::Destroying => 'Destroying',
            DeploymentStatus::Destroyed => 'Destroyed',
            DeploymentStatus::Failed => 'Failed',
        };
    }

    public static function tone(DeploymentStatus $status): string
    {
        return match ($status) {
            DeploymentStatus::Pending, DeploymentStatus::Starting => 'info',
            DeploymentStatus::Running => 'ok',
            DeploymentStatus::Hibernated => 'warn',
            DeploymentStatus::Destroying, DeploymentStatus::Destroyed => 'idle',
            DeploymentStatus::Failed => 'fail',
        };
    }
}
