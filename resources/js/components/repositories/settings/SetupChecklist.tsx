import type { YakConfig } from '@/types/repositories';
import type { RepositorySettingsHeader } from '@/types/repositorySettings';
import { SettingsCard } from '@/components/repositories/settings/SettingsCard';

type ItemState = 'done' | 'waiting' | 'todo';

type Item = { key: string; label: string; state: ItemState; link?: { href: string; label: string } };

const PILL: Record<ItemState, { label: string; className: string }> = {
    done: { label: 'Done', className: 'bg-ok-soft text-ok' },
    waiting: { label: 'Waiting', className: 'bg-warn-soft text-warn' },
    todo: { label: 'To do', className: 'bg-panel-2 text-muted' },
};

const SHADOW_DAYS_REQUIRED = 14;

/** The steps before Yak can approve pull requests. Renders nothing once every step is done. */
export function SetupChecklist({
    header,
    yakConfig,
    hasSandbox,
    shadowDays,
    concernsPullRequest,
}: {
    header: RepositorySettingsHeader;
    yakConfig: YakConfig;
    hasSandbox: boolean;
    shadowDays: number;
    concernsPullRequest: { number: number; url: string } | null | undefined;
}) {
    const hasConfigFile = yakConfig.files.some((file) => file.name === 'config.yml');
    const configPullRequest = yakConfig.configPullRequest;

    const items: Item[] = [
        { key: 'sandbox', label: 'Sandbox built', state: hasSandbox ? 'done' : 'todo' },
        {
            key: 'config',
            label: '.yak/config.yml merged',
            state: hasConfigFile ? 'done' : configPullRequest ? 'waiting' : 'todo',
            link: !hasConfigFile && configPullRequest ? { href: configPullRequest.url, label: `Review PR #${configPullRequest.number}` } : undefined,
        },
        { key: 'review', label: 'PR review on', state: header.prReviewEnabled ? 'done' : 'todo' },
        {
            key: 'concerns',
            label: 'Protected assets file merged',
            state: yakConfig.values.hasConcernsFile ? 'done' : concernsPullRequest ? 'waiting' : 'todo',
            link: !yakConfig.values.hasConcernsFile && concernsPullRequest ? { href: concernsPullRequest.url, label: `Review PR #${concernsPullRequest.number}` } : undefined,
        },
        { key: 'shadow', label: 'Two weeks of shadow before arming', state: header.approval.level >= 2 && shadowDays >= SHADOW_DAYS_REQUIRED ? 'done' : 'todo' },
    ];
    const doneCount = items.filter((item) => item.state === 'done').length;

    if (doneCount === items.length) {
        return null;
    }

    return (
        <SettingsCard
            title="Getting started"
            description={`${doneCount} of ${items.length} done. Yak approves nothing until the last two are done.`}
            testId="setup-checklist"
            aside={<span className="rounded-full bg-panel-2 px-2.5 py-0.5 text-[12px] font-medium text-body">{doneCount} of {items.length}</span>}
        >
            <ol className="flex flex-col gap-2 text-[13px]">
                {items.map((item) => (
                    <li key={item.key} className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                        <span className={`inline-flex w-16 justify-center rounded-full px-2 py-0.5 text-[11px] font-medium ${PILL[item.state].className}`}>{PILL[item.state].label}</span>
                        {item.label}
                        {item.link && (
                            <a href={item.link.href} target="_blank" rel="noopener noreferrer" className="ml-auto font-semibold text-accent-text hover:underline">
                                {item.link.label}
                            </a>
                        )}
                    </li>
                ))}
            </ol>
        </SettingsCard>
    );
}
