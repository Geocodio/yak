import { Button } from '@geocodio/console-ui';
import { Monitor } from 'lucide-react';
import { usePage } from '@inertiajs/react';
import { formatTime } from '@/components/shell/formatTime';
import { CopyButton } from '@/components/threads/environment/LinkActions';
import { ownerLabel } from '@/components/threads/teleport/TeleportNotice';
import { sshKeys } from '@/routes/settings';
import type { PanelProps } from '@/threads/panels';
import type { ThreadEnvironment } from '@/types/thread-changes';
import type { ThreadPageData } from '@/types/threads';
import { OverviewGroup, OverviewList, OverviewRow, RowNote } from './OverviewRow';

/** How to get into the sandbox: the terminal command, and where the thread runs with the Teleport button. */
export function AccessGroup({ thread, environment, onTeleport }: PanelProps & { environment: ThreadEnvironment }) {
    const { teleport } = usePage<ThreadPageData & Record<string, unknown>>().props;
    const isOnDevice = teleport.state === 'on_device';
    const command = `yak ssh ${thread.id}`;

    return (
        <OverviewGroup title="Access" testId="overview-access">
            <OverviewList>
                <OverviewRow label="Terminal" testId="environment-terminal">
                    <code className="min-w-0 flex-1 truncate font-mono text-[12px]">{command}</code>
                    <CopyButton text={command} label="SSH command" testId="copy-address" />
                    <RowNote>
                        From your computer, with your GitHub keys.{' '}
                        <a href={sshKeys().url} className="text-accent-text hover:underline" data-testid="environment-ssh-link">
                            Set up SSH
                        </a>
                    </RowNote>
                </OverviewRow>

                <OverviewRow label="Runs on" testId="environment-runs-on">
                    <span className="min-w-0 flex-1 truncate">{isOnDevice ? ownerLabel(teleport) : 'Yak sandbox'}</span>
                    {teleport.canTeleport && teleport.state !== 'teleporting' && onTeleport !== undefined && (
                        <Button variant="secondary" className="h-7 shrink-0" icon={<Monitor size={12} />} onClick={onTeleport} data-testid={isOnDevice ? 'teleport-up' : 'teleport-button'}>
                            {isOnDevice ? 'Teleport to Yak' : 'Teleport'}
                        </Button>
                    )}
                    <RowNote>
                        {isOnDevice
                            ? `${teleport.since ? `Since ${formatTime(teleport.since)}. ` : ''}${teleport.heldEvents.length} held ${teleport.heldEvents.length === 1 ? 'event' : 'events'}.`
                            : 'Teleport moves this thread to your computer.'}
                    </RowNote>
                    {teleport.environmentNote && (
                        <details className="basis-full text-[12.5px] text-muted">
                            <summary className="cursor-pointer text-body">Environment note</summary>
                            <pre className="mt-2 whitespace-pre-wrap font-sans">{teleport.environmentNote}</pre>
                        </details>
                    )}
                </OverviewRow>
            </OverviewList>

            {environment.notUploadedDirectories.length > 0 && (
                <section className="mt-4 border-t border-hair pt-3" data-testid="environment-not-uploaded">
                    <h4 className="mb-1 text-[12px] text-muted">Not uploaded from your computer</h4>
                    <ul className="flex flex-col gap-1">
                        {environment.notUploadedDirectories.map((directory) => (
                            <li key={directory}>
                                <code className="font-mono text-[12px] text-body">{directory}</code>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </OverviewGroup>
    );
}
