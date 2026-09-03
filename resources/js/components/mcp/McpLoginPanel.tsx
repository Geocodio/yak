import { Button, TextInput, cn } from '@geocodio/console-ui';
import { CheckCircle2, Clock, ExternalLink, Loader2, XCircle } from 'lucide-react';
import { useState } from 'react';
import { useRouterAction } from '@/lib/useRouterAction';
import mcp from '@/routes/mcp';
import type { McpLoginSessionData, McpServerRow } from '@/types/settings';

type StepState = 'done' | 'current' | 'upcoming';

export function McpLoginPanel({
    server,
    session,
    sshHost,
    onDismiss,
    onRetry,
}: {
    server: McpServerRow;
    session: McpLoginSessionData;
    sshHost: string | null;
    onDismiss: () => void;
    onRetry: () => void;
}) {
    const action = useRouterAction();
    const [redirectUrl, setRedirectUrl] = useState('');
    const sshLoginCommand = sshHost ? `ssh -t root@${sshHost} ${server.loginCommand}` : server.loginCommand;

    const finish = () => {
        action.run('finish-login', 'post', mcp.login.redirect.url(server.name), { redirectUrl });
    };

    const cancel = () => {
        action.run(`login-cancel-${server.name}`, 'delete', mcp.login.cancel.url(server.name));
    };

    return (
        <div className="border-t border-hair bg-accent-soft/40 px-4 py-4" data-testid={`mcp-login-panel-${server.name}`}>
            <div className="rounded-card border border-hair bg-panel p-4 shadow-card">
                <Stepper session={session} />

                <div className="mt-4">
                    {session.status === 'starting' && (
                        <div className="flex items-center gap-2 text-[12.5px] text-muted">
                            <Loader2 size={14} className="animate-spin" />
                            Asking {server.displayName} for an authorization link…
                        </div>
                    )}

                    {session.status === 'awaiting_redirect' && (
                        <div className="flex flex-col gap-3">
                            <p className="text-[12.5px] leading-relaxed text-muted">
                                Open the authorization page, approve access, then copy the localhost address the browser lands on &mdash; it will
                                not load, that&apos;s expected &mdash; and paste it below.
                            </p>
                            <div className="flex items-center gap-3">
                                {session.authorizationUrl && (
                                    <a href={session.authorizationUrl} target="_blank" rel="noopener noreferrer">
                                        <Button variant="primary" icon={<ExternalLink size={13} />} data-testid="mcp-open-authorization">
                                            Open authorization page
                                        </Button>
                                    </a>
                                )}
                                <button
                                    type="button"
                                    className="text-[12px] text-muted underline"
                                    onClick={() => session.authorizationUrl && navigator.clipboard.writeText(session.authorizationUrl)}
                                >
                                    Copy link
                                </button>
                            </div>
                            {session.authorizationUrl && (
                                <div className="truncate font-mono text-[11.5px] text-faint">{session.authorizationUrl}</div>
                            )}
                            <div className="flex items-center gap-2">
                                <TextInput
                                    value={redirectUrl}
                                    onChange={(e) => setRedirectUrl(e.target.value)}
                                    placeholder="http://localhost:PORT/callback?code=..."
                                    className="flex-1"
                                    data-testid="mcp-redirect-input"
                                />
                                <Button
                                    variant="primary"
                                    pending={action.isPending('finish-login')}
                                    disabled={redirectUrl.trim() === ''}
                                    onClick={finish}
                                    data-testid="mcp-finish-login"
                                >
                                    Finish connecting
                                </Button>
                            </div>
                        </div>
                    )}

                    {session.status === 'finishing' && (
                        <div className="flex items-center gap-2 text-[12.5px] text-muted">
                            <Loader2 size={14} className="animate-spin" />
                            Finishing…
                        </div>
                    )}

                    {session.status === 'succeeded' && (
                        <SuccessCard displayName={server.displayName} onDismiss={onDismiss} />
                    )}

                    {session.status === 'failed' && <FailedCard error={session.error} onRetry={onRetry} />}

                    {(session.status === 'expired' || session.status === 'cancelled') && (
                        <NeutralOutcomeCard status={session.status} onRetry={onRetry} />
                    )}
                </div>

                {(session.status === 'starting' || session.status === 'awaiting_redirect' || session.status === 'finishing') && (
                    <div className="mt-4 flex items-center justify-between border-t border-hair pt-3 text-[11.5px] text-faint">
                        <span className="flex flex-col gap-0.5">
                            <span className="flex items-center gap-1.5">
                                <Clock size={12} />
                                This login session stays open for 10 minutes.
                            </span>
                            <span>
                                Prefer the terminal? <code className="font-mono">{sshLoginCommand}</code>
                            </span>
                        </span>
                        <div className="flex items-center gap-3">
                            {session.status === 'awaiting_redirect' && session.authorizationUrl && (
                                <a href={session.authorizationUrl} target="_blank" rel="noopener noreferrer" className="underline">
                                    Lost the page? Reopen the authorization link
                                </a>
                            )}
                            <button type="button" className="underline" onClick={cancel}>
                                Cancel
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

function Stepper({ session }: { session: McpLoginSessionData }) {
    const authorizeState: StepState = session.authorizationUrl || session.status === 'finishing' || session.status === 'succeeded' ? 'done' : 'current';
    const pasteState: StepState =
        session.status === 'finishing' || session.status === 'succeeded'
            ? 'done'
            : session.status === 'awaiting_redirect'
              ? 'current'
              : 'upcoming';
    const doneState: StepState = session.status === 'succeeded' ? 'done' : session.status === 'finishing' ? 'current' : 'upcoming';

    const steps: { label: string; state: StepState }[] = [
        { label: 'Authorize', state: authorizeState },
        { label: 'Paste the redirect URL', state: pasteState },
        { label: 'Done', state: doneState },
    ];

    return (
        <ol className="flex items-center gap-4">
            {steps.map((step, index) => (
                <li key={step.label} className="flex items-center gap-2">
                    {step.state === 'done' ? (
                        <CheckCircle2 size={15} className="text-ok" />
                    ) : (
                        <span
                            className={cn(
                                'flex size-[15px] items-center justify-center rounded-full border',
                                step.state === 'current' ? 'border-accent bg-accent text-white' : 'border-hair',
                            )}
                        />
                    )}
                    <span className={cn('text-[12px]', step.state === 'upcoming' ? 'text-faint' : 'text-body')}>{step.label}</span>
                    {index < steps.length - 1 && <span className="ml-2 h-px w-6 bg-hair" />}
                </li>
            ))}
        </ol>
    );
}

function SuccessCard({ displayName, onDismiss }: { displayName: string; onDismiss: () => void }) {
    return (
        <div className="flex items-start gap-3 rounded-card border border-ok/30 bg-panel p-3">
            <div className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-ok-soft text-ok">
                <CheckCircle2 size={16} />
            </div>
            <div className="flex-1">
                <div className="text-[13px] font-medium">{displayName} is connected</div>
                <p className="mt-1 text-[12px] leading-relaxed text-muted">
                    The credential is stored in the shared Claude Code config, so every sandbox picks it up on its next run. Tokens refresh on
                    their own.
                </p>
            </div>
            <button type="button" className="shrink-0 text-[12px] text-muted underline" onClick={onDismiss}>
                Dismiss
            </button>
        </div>
    );
}

function FailedCard({ error, onRetry }: { error: string | null; onRetry: () => void }) {
    return (
        <div className="flex items-start gap-3 rounded-card border border-fail/30 bg-panel p-3">
            <div className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-fail-soft text-fail">
                <XCircle size={16} />
            </div>
            <div className="flex-1">
                <div className="text-[13px] font-medium">Could not complete the login</div>
                {error && <pre className="mt-2 whitespace-pre-wrap rounded-control bg-panel-2 p-2 font-mono text-[11.5px] text-fail">{error}</pre>}
                <p className="mt-2 text-[12px] leading-relaxed text-muted">
                    Start again, and paste the redirect URL within ten minutes of approving.
                </p>
                <Button variant="primary" className="mt-3 h-7 px-2.5 text-[12px]" onClick={onRetry}>
                    Try again
                </Button>
            </div>
        </div>
    );
}

function NeutralOutcomeCard({ status, onRetry }: { status: 'expired' | 'cancelled'; onRetry: () => void }) {
    return (
        <div className="flex items-start gap-3 rounded-card border border-hair bg-panel p-3">
            <div className="flex-1">
                <div className="text-[13px] font-medium">Login {status === 'expired' ? 'expired' : 'cancelled'}</div>
                <Button variant="primary" className="mt-3 h-7 px-2.5 text-[12px]" onClick={onRetry}>
                    Try again
                </Button>
            </div>
        </div>
    );
}
