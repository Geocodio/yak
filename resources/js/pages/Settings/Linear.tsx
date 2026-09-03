import { Head, router } from '@inertiajs/react';
import { Button, ConfirmDialog, Toggle } from '@geocodio/console-ui';
import { CheckCircle2 } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { SettingsLayout } from '@/layouts/SettingsLayout';
import { redirect } from '@/routes/auth/linear';
import { disconnect, update } from '@/routes/settings/linear';
import type { PageProps } from '@/types/shared';
import type { LinearConnectionData } from '@/types/settings';

type Props = PageProps<{ linear: LinearConnectionData }>;

function Card({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={`rounded-card border border-hair bg-panel p-4 shadow-card ${className ?? ''}`}>{children}</div>;
}

export default function Linear({ linear }: Props) {
    const [confirmDisconnect, setConfirmDisconnect] = useState(false);
    const [disconnecting, setDisconnecting] = useState(false);

    const toggleStartedState = (checked: boolean) => {
        router.patch(update.url(), { moveIssuesToStartedState: checked }, { preserveScroll: true });
    };

    const doDisconnect = () => {
        setDisconnecting(true);
        router.delete(disconnect.url(), {
            onFinish: () => {
                setDisconnecting(false);
                setConfirmDisconnect(false);
            },
        });
    };

    return (
        <>
            <Head title="Linear connection" />

            <div className="flex flex-col gap-4">
                {!linear.oauthConfigured && (
                    <Card className="border-warn/30">
                        <div className="text-[13px] font-medium">OAuth is not configured</div>
                        <p className="mt-1 text-[12px] text-muted">
                            Set <code>YAK_LINEAR_OAUTH_CLIENT_ID</code>, <code>YAK_LINEAR_OAUTH_CLIENT_SECRET</code>, and{' '}
                            <code>YAK_LINEAR_OAUTH_REDIRECT_URI</code> in the container&apos;s environment, then refresh this page.
                        </p>
                    </Card>
                )}

                {linear.oauthConfigured && linear.isConnected && (
                    <>
                        <Card>
                            <div className="flex items-start gap-4">
                                <div className="mt-1 flex size-10 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent">
                                    <CheckCircle2 size={18} />
                                </div>
                                <div className="flex-1">
                                    <div className="text-[13px] font-medium">Connected to {linear.workspaceName}</div>
                                    <dl className="mt-2 grid grid-cols-2 gap-y-1 text-[12px]">
                                        <dt className="text-faint">Workspace ID</dt>
                                        <dd className="font-mono">{linear.workspaceId}</dd>
                                        <dt className="text-faint">Actor</dt>
                                        <dd>{linear.actor}</dd>
                                        <dt className="text-faint">Scopes</dt>
                                        <dd className="truncate">{linear.scopes && linear.scopes.length > 0 ? linear.scopes.join(', ') : '—'}</dd>
                                        <dt className="text-faint">Access token expires</dt>
                                        <dd>{linear.expiresIn ?? '—'}</dd>
                                    </dl>
                                </div>
                            </div>
                            <div className="mt-4 flex items-center gap-3">
                                <a href={redirect.url()}>
                                    <Button>Reconnect</Button>
                                </a>
                                <Button variant="destructive" onClick={() => setConfirmDisconnect(true)}>
                                    Disconnect
                                </Button>
                            </div>
                        </Card>

                        <Card className="flex items-start justify-between gap-6">
                            <div>
                                <div className="text-[13px] font-medium">Move issues to In Progress when Yak picks them up</div>
                                <p className="mt-0.5 text-[12px] text-muted">
                                    Yak moves the Linear issue to its team&apos;s first &quot;started&quot; workflow state when it begins working. Set{' '}
                                    <code>YAK_LINEAR_STARTED_STATE_ID</code> to override with a specific state.
                                </p>
                            </div>
                            <Toggle
                                checked={linear.moveIssuesToStartedState}
                                onCheckedChange={toggleStartedState}
                                label="Move issues to In Progress when Yak picks them up"
                            />
                        </Card>

                        {linear.disconnectedAt && (
                            <Card className="border-fail/30">
                                <div className="text-[13px] font-medium">Connection invalidated</div>
                                <p className="mt-1 text-[12px] text-muted">
                                    Linear rejected a token refresh {linear.disconnectedAgo}. Reconnect above to resume posting comments.
                                </p>
                            </Card>
                        )}
                    </>
                )}

                {linear.oauthConfigured && !linear.isConnected && (
                    <Card>
                        <p className="text-[13px] text-muted">
                            Linear is not connected yet. Click below to authorize Yak &mdash; comments and state updates will post as the Yak app rather
                            than the connecting user.
                        </p>
                        <div className="mt-4">
                            <a href={redirect.url()}>
                                <Button variant="primary">Connect Linear</Button>
                            </a>
                        </div>
                    </Card>
                )}
            </div>

            <ConfirmDialog
                open={confirmDisconnect}
                onOpenChange={setConfirmDisconnect}
                title="Disconnect Linear"
                body="You'll need to reauthorize to post comments again."
                confirmLabel="Disconnect"
                busy={disconnecting}
                onConfirm={doDisconnect}
            />
        </>
    );
}

Linear.layout = (page: ReactNode) => <SettingsLayout slug="linear">{page}</SettingsLayout>;
