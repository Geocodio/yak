import { Link } from '@inertiajs/react';
import { Button } from '@geocodio/console-ui';
import { ExternalLink } from 'lucide-react';
import { levelColor } from '@/components/approvals/levels';
import type { RepositorySettingsHeader } from '@/types/repositorySettings';

const NEUTRAL_PILL = 'bg-panel-2 text-muted';

function Pill({ className, children }: { className: string; children: React.ReactNode }) {
    return <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-[12px] font-medium ${className}`}>{children}</span>;
}

export function defconLabel({ level, mode }: RepositorySettingsHeader['approval']): string {
    const words = level === 1 || mode === 'off' ? 'review only' : mode === 'enforce' ? 'armed' : 'shadow';

    return `DEFCON ${level} · ${words}`;
}

/** The page title and the GitHub button. The pills follow in `SettingsPills`. */
export function SettingsTitle({ header }: { header: RepositorySettingsHeader }) {
    return (
        <div className="mb-3 flex flex-wrap items-center justify-between gap-x-3 gap-y-2" data-testid="settings-header">
            <h1 className="text-[20px] font-semibold tracking-tight">{header.slug}</h1>
            {header.githubUrl && (
                <a href={header.githubUrl} target="_blank" rel="noopener noreferrer">
                    <Button icon={<ExternalLink size={13} />}>View on GitHub</Button>
                </a>
            )}
        </div>
    );
}

export function SettingsPills({ header }: { header: RepositorySettingsHeader }) {
    const approvalsUrl = header.sections.find((section) => section.slug === 'approvals')?.url ?? '#';

    return (
        <div className="mb-4 flex flex-wrap items-center gap-x-3 gap-y-2" data-testid="settings-pills">
            <Pill className={header.isActive ? 'bg-ok-soft text-ok' : NEUTRAL_PILL}>{header.isActive ? 'Active' : 'Inactive'}</Pill>
            <Pill className={NEUTRAL_PILL}>PR review {header.prReviewEnabled ? 'on' : 'off'}</Pill>
            <Link
                href={approvalsUrl}
                data-testid="header-defcon"
                className="inline-flex items-center rounded-full px-2.5 py-0.5 font-mono text-[12px] font-medium hover:opacity-80"
                style={{ background: levelColor(header.approval.level, 'tint'), color: levelColor(header.approval.level, 'text') }}
            >
                {defconLabel(header.approval)}
            </Link>
            <Pill className={NEUTRAL_PILL}>Previews {header.deploymentsEnabled ? 'on' : 'off'}</Pill>
        </div>
    );
}
