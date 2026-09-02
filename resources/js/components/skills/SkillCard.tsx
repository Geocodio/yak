import { Badge, Button, Toggle } from '@geocodio/console-ui';
import { ExternalLink } from 'lucide-react';
import type { AvailableSkillRow, BundledSkillRow, InstalledSkillRow } from '@/types/skills';

type InstalledCardProps = {
    variant: 'installed';
    skill: InstalledSkillRow;
    onToggle: (enabled: boolean) => void;
    onUpdate: () => void;
    onUninstall: () => void;
    toggling: boolean;
    updating: boolean;
    uninstalling: boolean;
};

type BundledCardProps = {
    variant: 'bundled';
    skill: BundledSkillRow;
};

type AvailableCardProps = {
    variant: 'available';
    skill: AvailableSkillRow;
    onInstall: () => void;
    installing: boolean;
};

type SkillCardProps = InstalledCardProps | BundledCardProps | AvailableCardProps;

export function SkillCard(props: SkillCardProps) {
    if (props.variant === 'bundled') {
        return (
            <div className="flex flex-col gap-2 rounded-card border border-hair bg-panel p-4 shadow-card" data-testid={`bundled-${props.skill.name}`}>
                <div className="flex items-center justify-between gap-2">
                    <span className="text-[13.5px] font-semibold">{props.skill.name}</span>
                    <Badge>bundled</Badge>
                </div>
                <p className="line-clamp-3 flex-1 text-[12.5px] leading-snug text-muted">{props.skill.description}</p>
            </div>
        );
    }

    if (props.variant === 'installed') {
        const { skill } = props;

        return (
            <div className="flex flex-col gap-2 rounded-card border border-hair bg-panel p-4 shadow-card" data-testid={`installed-${skill.key}`}>
                <div className="flex items-start justify-between gap-3">
                    <span className="text-[13.5px] font-semibold">{skill.name}</span>
                    <span className="whitespace-nowrap rounded-chip bg-panel-2 px-1.5 py-0.5 font-mono text-[11px] text-muted">{skill.version || '—'}</span>
                </div>
                <p className="flex-1 text-[12.5px] leading-snug text-muted">
                    Installed {skill.installedAgo}
                    {skill.lastUpdatedAgo ? `, updated ${skill.lastUpdatedAgo}` : ''}.
                </p>
                <div className="flex items-center justify-between border-t border-dashed border-hair pt-2.5">
                    <span className="text-[11px] text-faint">{skill.marketplace}</span>
                    <div className="flex items-center gap-2.5">
                        <Badge tone={skill.enabled ? 'ok' : 'neutral'}>{skill.enabled ? 'Enabled' : 'Disabled'}</Badge>
                        <Toggle
                            checked={skill.enabled}
                            onCheckedChange={props.onToggle}
                            label={`Toggle ${skill.name}`}
                            disabled={props.toggling}
                            data-testid={`toggle-${skill.key}`}
                        />
                    </div>
                </div>
                <div className="flex items-center justify-end gap-2">
                    <Button
                        variant="tertiary"
                        pending={props.updating}
                        pendingLabel="Updating…"
                        onClick={props.onUpdate}
                        data-testid={`update-${skill.key}`}
                    >
                        Update
                    </Button>
                    <Button
                        variant="tertiary"
                        className="text-fail"
                        pending={props.uninstalling}
                        pendingLabel="Removing…"
                        onClick={props.onUninstall}
                        data-testid={`uninstall-${skill.key}`}
                    >
                        Uninstall
                    </Button>
                </div>
            </div>
        );
    }

    const { skill } = props;

    return (
        <div className="flex flex-col gap-2 rounded-card border border-hair bg-panel p-4 shadow-card" data-testid={`available-${skill.key}`}>
            <div className="flex items-start justify-between gap-3">
                <div className="flex items-center gap-1.5">
                    <span className="text-[13.5px] font-semibold">{skill.name}</span>
                    {skill.link && (
                        <a href={skill.link} target="_blank" rel="noopener" aria-label="Plugin source" className="text-faint hover:text-accent-text">
                            <ExternalLink size={13} />
                        </a>
                    )}
                </div>
                {skill.category && <Badge>{skill.category}</Badge>}
            </div>
            <p className="line-clamp-3 flex-1 text-[12.5px] leading-snug text-muted">{skill.description}</p>
            <div className="flex items-center justify-between border-t border-dashed border-hair pt-2.5">
                <span className="text-[11px] text-faint">{skill.marketplace}</span>
                <Button variant="secondary" pending={props.installing} pendingLabel="Installing…" onClick={props.onInstall} data-testid={`install-${skill.key}`}>
                    Install
                </Button>
            </div>
        </div>
    );
}
