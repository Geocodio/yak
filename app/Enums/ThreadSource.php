<?php

namespace App\Enums;

enum ThreadSource: string
{
    case Slack = 'slack';
    case Linear = 'linear';
    case Sentry = 'sentry';
    case Github = 'github';
    case Email = 'email';
    case Dashboard = 'dashboard';
    case Cli = 'cli';
    case Teleport = 'teleport';
    case Stack = 'stack';
    case RepositoryResearch = 'repository_research';
    case PreviewRepair = 'preview_repair';

    public function label(): string
    {
        return match ($this) {
            self::Slack => 'Slack',
            self::Linear => 'Linear',
            self::Sentry => 'Sentry',
            self::Github => 'GitHub',
            self::Email => 'Email',
            self::Dashboard => 'Dashboard',
            self::Cli => 'CLI',
            self::Teleport => 'Teleport',
            self::Stack => 'Stack',
            self::RepositoryResearch => 'Repo research',
            self::PreviewRepair => 'Preview repair',
        };
    }
}
