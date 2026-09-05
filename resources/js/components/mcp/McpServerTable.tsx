import { Link, router } from '@inertiajs/react';
import { Badge, Button, cn } from '@geocodio/console-ui';
import { useState } from 'react';
import { skills } from '@/routes';
import { useRouterAction } from '@/lib/useRouterAction';
import { McpLoginPanel } from '@/components/mcp/McpLoginPanel';
import mcp from '@/routes/mcp';
import type { McpLoginSessionData, McpServerRow } from '@/types/settings';

const ACTIVE_STATUSES: McpLoginSessionData['status'][] = ['starting', 'awaiting_redirect', 'finishing'];

const GRID_COLS = 'grid-cols-1 md:grid-cols-[1.2fr_2fr_150px_170px_160px]';

export function McpServerTable({
    servers,
    loginSessions,
    sshHost,
    onRemove,
}: {
    servers: McpServerRow[];
    loginSessions: Record<string, McpLoginSessionData>;
    sshHost: string | null;
    onRemove: (server: McpServerRow) => void;
}) {
    const action = useRouterAction();
    const [dismissed, setDismissed] = useState<Set<string>>(new Set());
    const [rechecking, setRechecking] = useState<string | null>(null);

    const recheckRow = (name: string) => {
        setRechecking(name);
        router.reload({ only: ['servers', 'checkedAgo'], onFinish: () => setRechecking(null) });
    };

    const startLogin = (name: string) => {
        setDismissed((prev) => {
            if (!prev.has(name)) {
                return prev;
            }
            const next = new Set(prev);
            next.delete(name);
            return next;
        });
        action.run(`login-start-${name}`, 'post', mcp.login.start.url(name));
    };

    return (
        <div className="overflow-x-auto rounded-card border border-hair bg-panel shadow-card">
            <div className={cn('hidden gap-2 border-b border-hair bg-panel-2 px-4 py-2 text-[11.5px] font-medium text-faint md:grid', GRID_COLS)}>
                <span>Server</span>
                <span>Command or URL</span>
                <span>Source</span>
                <span>Status</span>
                <span />
            </div>

            {servers.map((server) => {
                const session = loginSessions[server.name];
                const showPanel = !!session && !dismissed.has(server.name);
                const sessionActive = !!session && ACTIVE_STATUSES.includes(session.status);

                return (
                    <div key={server.name} className="border-b border-hair last:border-b-0" data-testid={`mcp-row-${server.name}`}>
                        <div className={cn('grid items-center gap-2 px-4 py-3', GRID_COLS)}>
                            <div>
                                <div className="text-[13px] font-medium">{server.displayName}</div>
                                {server.status === 'failed' && server.detail && (
                                    <div className="mt-0.5 truncate text-[11.5px] text-fail">{server.detail}</div>
                                )}
                            </div>
                            <div className="truncate font-mono text-[12px] text-muted" title={server.target}>
                                {server.target}
                            </div>
                            <SourceCell server={server} />
                            <div>
                                <StatusBadge server={server} />
                            </div>
                            <div className="flex flex-wrap items-center justify-start gap-2 md:justify-end">
                                {server.canConnect && !sessionActive && (
                                    <Button
                                        variant="primary"
                                        className="h-7 px-2.5 text-[12px]"
                                        pending={action.isPending(`login-start-${server.name}`)}
                                        onClick={() => startLogin(server.name)}
                                        data-testid={`mcp-connect-${server.name}`}
                                    >
                                        Connect
                                    </Button>
                                )}
                                {sessionActive && (
                                    <Button
                                        variant="tertiary"
                                        className="h-7 px-2 text-[12px]"
                                        pending={action.isPending(`login-cancel-${server.name}`)}
                                        onClick={() => action.run(`login-cancel-${server.name}`, 'delete', mcp.login.cancel.url(server.name))}
                                    >
                                        Cancel
                                    </Button>
                                )}
                                {server.canLogout && (
                                    <Button
                                        variant="tertiary"
                                        className="h-7 px-2 text-[12px]"
                                        pending={action.isPending(`logout-${server.name}`)}
                                        onClick={() => action.run(`logout-${server.name}`, 'post', mcp.logout.url(server.name))}
                                        data-testid={`mcp-logout-${server.name}`}
                                    >
                                        Log out
                                    </Button>
                                )}
                                {server.status === 'failed' && (
                                    <Button
                                        variant="tertiary"
                                        className="h-7 px-2 text-[12px]"
                                        pending={rechecking === server.name}
                                        onClick={() => recheckRow(server.name)}
                                    >
                                        Re-check
                                    </Button>
                                )}
                                {server.canRemove && (
                                    <Button
                                        variant="tertiary"
                                        className="h-7 px-2 text-[12px] text-fail"
                                        onClick={() => onRemove(server)}
                                        data-testid={`mcp-remove-${server.name}`}
                                    >
                                        Remove
                                    </Button>
                                )}
                            </div>
                        </div>

                        {showPanel && session && (
                            <McpLoginPanel
                                server={server}
                                session={session}
                                sshHost={sshHost}
                                onDismiss={() => setDismissed((prev) => new Set(prev).add(server.name))}
                                onRetry={() => startLogin(server.name)}
                            />
                        )}
                    </div>
                );
            })}
        </div>
    );
}

function SourceCell({ server }: { server: McpServerRow }) {
    if (server.source === 'deploy') {
        return (
            <div className="text-[12.5px]">
                <div>Deploy config</div>
                <div className="text-[11px] text-faint">managed by Ansible</div>
            </div>
        );
    }

    if (server.source === 'plugin') {
        return (
            <div className="text-[12.5px]">
                <div>
                    Plugin{' '}
                    <Link href={skills.url()} className="text-accent-text underline">
                        {server.pluginName}
                    </Link>
                </div>
                <div className="text-[11px] text-faint">remove from Skills</div>
            </div>
        );
    }

    return <div className="text-[12.5px]">Added here</div>;
}

function StatusBadge({ server }: { server: McpServerRow }) {
    switch (server.status) {
        case 'connected':
            return <Badge tone="ok">Connected</Badge>;
        case 'needs_auth':
            return <Badge tone="warn">Needs login</Badge>;
        case 'failed':
            return <Badge tone="fail">Failed</Badge>;
        case 'pending_approval':
            return <Badge tone="neutral">Pending approval</Badge>;
        case 'token':
            return <Badge tone="neutral">Token configured</Badge>;
        default:
            return <Badge tone="neutral">Unknown</Badge>;
    }
}
