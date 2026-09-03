import { Deferred, Head, router, usePoll } from '@inertiajs/react';
import { Button, ConfirmDialog, EmptyState, Skeleton } from '@geocodio/console-ui';
import { Plus, RotateCw, Server as ServerIcon, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { SettingsLayout } from '@/layouts/SettingsLayout';
import { McpServerTable } from '@/components/settings/mcp/McpServerTable';
import { AddMcpServerDialog } from '@/components/settings/mcp/AddMcpServerDialog';
import { SshFallback } from '@/components/settings/mcp/SshFallback';
import mcp from '@/routes/settings/mcp';
import type { PageProps } from '@/types/shared';
import type { McpLoginSessionData, McpServerRow } from '@/types/settings';

type Props = PageProps<{
    servers: McpServerRow[] | undefined;
    loginSessions: Record<string, McpLoginSessionData>;
    checkedAgo: string | null;
    sshHost: string | null;
    docsUrl: string;
}>;

const ACTIVE_SESSION_STATUSES: McpLoginSessionData['status'][] = ['starting', 'awaiting_redirect', 'finishing'];

export default function McpServers({ servers, loginSessions, checkedAgo, sshHost, docsUrl }: Props) {
    const [showAddServer, setShowAddServer] = useState(false);
    const [pendingRemove, setPendingRemove] = useState<McpServerRow | null>(null);
    const [removing, setRemoving] = useState(false);
    const [rechecking, setRechecking] = useState(false);

    const hasActiveSession = Object.values(loginSessions).some((s) => ACTIVE_SESSION_STATUSES.includes(s.status));
    usePoll(2000, { only: ['loginSessions'] }, { autoStart: hasActiveSession });

    // usePoll refreshes loginSessions on its own; once one of them lands on
    // "succeeded" the server list is stale (the CLI can now see the token),
    // so pull it once rather than waiting for the next manual re-check.
    const previousStatuses = useRef<Record<string, McpLoginSessionData['status']>>({});
    useEffect(() => {
        const previous = previousStatuses.current;
        const justSucceeded = Object.entries(loginSessions).some(([name, session]) => session.status === 'succeeded' && previous[name] !== 'succeeded');

        if (justSucceeded) {
            router.reload({ only: ['servers', 'checkedAgo'] });
        }

        previousStatuses.current = Object.fromEntries(Object.entries(loginSessions).map(([name, session]) => [name, session.status]));
    }, [loginSessions]);

    const needsAuthCount = (servers ?? []).filter((s) => s.status === 'needs_auth').length;

    const recheck = () => {
        setRechecking(true);
        router.reload({ only: ['servers', 'checkedAgo'], onFinish: () => setRechecking(false) });
    };

    const remove = () => {
        if (!pendingRemove) {
            return;
        }

        setRemoving(true);
        router.delete(mcp.destroy.url(pendingRemove.name), {
            preserveScroll: true,
            onFinish: () => {
                setRemoving(false);
                setPendingRemove(null);
            },
        });
    };

    return (
        <>
            <Head title="MCP servers" />

            <div className="flex flex-col gap-4">
                <div className="flex items-start justify-between gap-6">
                    <p className="max-w-prose text-[13px] leading-relaxed text-muted">
                        Tool servers the agent can reach inside every sandbox. Servers from Yak&apos;s generated config use tokens set at deploy
                        time; servers added here may need a one-time login. See the{' '}
                        <a href={docsUrl} target="_blank" rel="noopener noreferrer" className="text-accent-text underline">
                            MCP servers guide
                        </a>
                        .
                    </p>
                    <div className="flex shrink-0 items-center gap-3">
                        {checkedAgo && <span className="text-[12px] text-faint">Checked {checkedAgo}</span>}
                        <Button
                            variant="secondary"
                            icon={<RotateCw size={13} />}
                            pending={rechecking}
                            pendingLabel="Checking…"
                            onClick={recheck}
                            data-testid="mcp-recheck"
                        >
                            Re-check
                        </Button>
                        <Button
                            variant="primary"
                            icon={<Plus size={13} />}
                            onClick={() => setShowAddServer(true)}
                            data-testid="mcp-add-server"
                        >
                            Add server
                        </Button>
                    </div>
                </div>

                {needsAuthCount > 0 && (
                    <WarnStrip>
                        <b>
                            {needsAuthCount} server{needsAuthCount === 1 ? '' : 's'} need{needsAuthCount === 1 ? 's' : ''} a login.
                        </b>{' '}
                        Agents skip their tools until someone connects them.
                    </WarnStrip>
                )}

                <Deferred data="servers" fallback={<TableSkeleton />}>
                    {servers && servers.length === 0 ? (
                        <EmptyState
                            title="No MCP servers configured."
                            body="Add one below, or check the deploy config for servers set at provisioning time."
                            icon={<ServerIcon size={20} />}
                            action={
                                <Button variant="primary" icon={<Plus size={13} />} onClick={() => setShowAddServer(true)}>
                                    Add server
                                </Button>
                            }
                        />
                    ) : (
                        <McpServerTable servers={servers ?? []} loginSessions={loginSessions} sshHost={sshHost} onRemove={setPendingRemove} />
                    )}
                </Deferred>

                <SshFallback sshHost={sshHost} />
            </div>

            <AddMcpServerDialog open={showAddServer} onOpenChange={setShowAddServer} />

            <ConfirmDialog
                open={pendingRemove !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingRemove(null);
                    }
                }}
                title={`Remove ${pendingRemove?.name}?`}
                body="Removes the server from the shared config and clears its stored login. Agents lose its tools on their next run. Servers that come from a plugin or from the deploy config cannot be removed here."
                confirmLabel="Remove"
                busy={removing}
                confirmTestId="mcp-remove-confirm"
                onConfirm={remove}
            />
        </>
    );
}

function WarnStrip({ children }: { children: ReactNode }) {
    return (
        <div className="flex items-start gap-2 rounded-card border border-warn/30 bg-warn-soft p-3 text-[12.5px] leading-relaxed text-body">
            <TriangleAlert size={15} className="mt-0.5 shrink-0 text-warn" />
            <span>{children}</span>
        </div>
    );
}

function TableSkeleton() {
    return (
        <div className="overflow-hidden rounded-card border border-hair bg-panel shadow-card">
            <div className="border-b border-hair bg-panel-2 px-4 py-2 text-[11.5px] text-faint">Checking servers… this takes a few seconds.</div>
            <div className="flex flex-col gap-3 p-4">
                {[0, 1, 2].map((i) => (
                    <Skeleton key={i} className="h-8 w-full rounded-control" />
                ))}
            </div>
        </div>
    );
}

McpServers.layout = (page: ReactNode) => <SettingsLayout slug="mcp">{page}</SettingsLayout>;
