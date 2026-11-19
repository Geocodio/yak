import { router } from '@inertiajs/react';
import { Badge, Button, ConfirmDialog, Textarea } from '@geocodio/console-ui';
import { AlertTriangle, Check, ExternalLink, GitPullRequest, Link as LinkIcon, RefreshCw, UserX } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useRouterAction } from '@/lib/useRouterAction';
import { newMethod as newThread } from '@/routes/threads';
import type { CoOwnerAttempt, CoOwnerData, CoOwnerDecisionError, CoOwnerLinkError } from '@/types/coOwner';

/** The decision endpoints of the page the panel sits on: the task routes or the thread routes. */
export type CoOwnerRoutes = { approve: string; requestChanges: string; reject: string; withdraw: string; checkAccess: string };

const MINUS = '−';

const HOLD_SENTENCES: Record<string, string> = {
    floor: "It changes Yak's own config files, which always need a co-owner.",
};
const UNCHECKED_SENTENCE = 'Yak could not check every changed file against the risk profile, so it holds the change to be safe.';

const OUTCOME_TEXT: Record<string, string> = {
    asked: 'Asked.',
    not_linked: 'No linked user with write access.',
    no_write_access: 'Linked, but without write access.',
    error: 'Yak could not check this one.',
    team_skipped: 'Yak did not expand this team.',
    unknown: 'Yak could not read this entry.',
};

function attemptLabel(kind: string): string {
    return kind === 'tagged' ? 'Tagged by the requester' : kind === 'fallback' ? 'Fallback owner' : kind === 'changes_requester' ? 'Requested changes' : 'CODEOWNERS';
}

function clock(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false });
}

function dateAndTime(iso: string): string {
    const date = new Date(iso);
    return `${date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} at ${date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })}`;
}

function daysLeft(iso: string): string {
    const days = Math.max(0, Math.ceil((new Date(iso).getTime() - Date.now()) / 86_400_000));
    return `${days} ${days === 1 ? 'day' : 'days'}`;
}

function firstName(name: string): string {
    return name.split(' ')[0];
}

function joinNames(items: string[]): string {
    return items.length < 2 ? items.join('') : `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`;
}

function relativeTime(iso: string): string {
    const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60_000);
    const format = new Intl.RelativeTimeFormat('en', { numeric: 'always' });

    if (minutes < 1) {
        return 'just now';
    }

    return minutes < 60 ? format.format(-minutes, 'minute') : minutes < 1440 ? format.format(-Math.round(minutes / 60), 'hour') : format.format(-Math.round(minutes / 1440), 'day');
}

/** Who asked and when, what it touches and who is next, in the words of the thread's held-change card. */
function cardSentences(coOwner: CoOwnerData, isRequesterView: boolean, holdSentence: string | null): string[] {
    const asked = coOwner.asked;
    const ago = relativeTime(coOwner.heldAt);
    const sentences: string[] = [];

    if (isRequesterView) {
        if (asked.length > 0) {
            sentences.push(`Yak asked ${joinNames(asked)} ${ago}.`);
        }
    } else {
        const areas = coOwner.areas.map((area) => area.name);
        const owners = asked.length === 0 ? '' : coOwner.viewerIsAsked === true ? ' that you own' : ` that ${joinNames(asked)} own`;

        if (areas.length > 0) {
            sentences.push(`This change touches ${joinNames(areas)} code${owners}.`);
        } else if (holdSentence !== null) {
            sentences.push(holdSentence);
        }
        if (asked.length > 0) {
            sentences.push(coOwner.viewerIsAsked === true ? `${firstName(coOwner.requesterName)} asked you ${ago}.` : `${firstName(coOwner.requesterName)} asked ${joinNames(asked)} ${ago}.`);
        }
    }
    if (coOwner.fallbackOwner !== null) {
        sentences.push(`If nobody answers by ${dateAndTime(coOwner.expiresAt)}, Yak asks ${coOwner.fallbackOwner}.`);
    }

    return sentences;
}

function readDecisionError(value: unknown): CoOwnerDecisionError | null {
    const candidate = Array.isArray(value) ? value[0] : value;
    return candidate !== null && typeof candidate === 'object' && 'message' in candidate ? (candidate as CoOwnerDecisionError) : null;
}

type PanelProps = {
    coOwner: CoOwnerData;
    routes: CoOwnerRoutes;
    /** The link the requester copies to share the request. */
    shareUrl: string;
    repoUrl: string | null;
    /** `card` is the compact card above the thread composer; `panel` is the thread page panel. */
    variant?: 'panel' | 'card';
    /** Opens the changed files; the card links to it when the change has more files than it lists. */
    onOpenChanges?: () => void;
};

/** The held-change panel, in the clarification slot of the thread page and as the card above the thread composer. */
export function CoOwnerPanel({ variant = 'panel', ...props }: PanelProps) {
    const { coOwner } = props;

    if (coOwner.status === 'expired' || coOwner.status === 'withdrawn') {
        return <FinalState coOwner={coOwner} />;
    }

    if (coOwner.status !== 'pending') {
        return null;
    }

    return <PendingPanel variant={variant} {...props} />;
}

function FinalState({ coOwner }: { coOwner: CoOwnerData }) {
    const isExpired = coOwner.status === 'expired';
    const names = coOwner.asked.join(', ');

    return (
        <section id="co-owner" className="rounded-card border border-hair-strong bg-panel shadow-card" data-testid="co-owner-panel">
            <div className="flex flex-col gap-1.5 px-6 py-5">
                <h2 className="text-[15px] font-semibold">{isExpired ? 'This request expired' : 'This request was withdrawn'}</h2>
                <p className="text-[13px] leading-normal">
                    {isExpired
                        ? 'No co-owner decided within 7 days, so Yak closed the task. No PR was opened.'
                        : `${coOwner.decidedBy ?? 'Someone'} withdrew it on ${dateAndTime(coOwner.decidedAt ?? coOwner.heldAt)}. No PR was opened.`}
                </p>
                <p className="text-[12.5px] leading-normal text-muted">
                    {isExpired
                        ? `Expired on ${dateAndTime(coOwner.decidedAt ?? coOwner.expiresAt)}.`
                        : `Yak deleted the branch ${coOwner.branch ?? ''}.${names !== '' ? ` The co-owner DM to ${names} was updated.` : ''}`}
                </p>
                {isExpired && (
                    <p className="text-[12.5px] leading-normal text-muted">
                        The branch {coOwner.branch} is kept. To try again, ask Yak in the original Slack thread or start a new task from this one.
                    </p>
                )}
            </div>
            <div className="flex justify-end rounded-b-card bg-app px-6 py-3.5">
                {/* The start-a-thread form takes no prefilled description, so this opens it empty. */}
                <Button variant="primary" onClick={() => router.visit(newThread.url())} data-testid="co-owner-new-task">
                    Start a new task from this
                </Button>
            </div>
        </section>
    );
}

function PendingPanel({ coOwner, routes, shareUrl, repoUrl, variant = 'panel', onOpenChanges }: PanelProps) {
    const action = useRouterAction();
    const [decisionError, setDecisionError] = useState<CoOwnerDecisionError | null>(null);
    const [feedbackOpen, setFeedbackOpen] = useState(false);
    const [feedback, setFeedback] = useState('');
    const [rejectOpen, setRejectOpen] = useState(false);
    const [copied, setCopied] = useState(false);
    const [lastAction, setLastAction] = useState<string | null>(null);

    const { viewer, repoName } = coOwner;
    const canApprove = viewer.access === 'write';
    const isRequesterView = viewer.isRequester && !canApprove;
    const isApproving = action.isPending('approve');

    const run = (key: string, url: string, data: Record<string, string> = {}, onSuccess?: () => void) => {
        setLastAction(key);
        action.run(key, 'post', url, data, {
            onStart: () => setDecisionError(null),
            onError: (errors) => setDecisionError(readDecisionError(errors.coOwner)),
            onSuccess,
        });
    };

    const copyLink = () => {
        navigator.clipboard?.writeText(shareUrl).then(() => setCopied(true)).catch(() => undefined);
    };

    const holdSentence = coOwner.areas.length === 0 && coOwner.holdReasons.length > 0
        ? coOwner.holdReasons.includes('floor') ? HOLD_SENTENCES.floor : UNCHECKED_SENTENCE
        : null;

    const body = isRequesterView
        ? 'Thanks for the request. Your branch is built and CI passed. Changes to this area get a second pair of eyes first, no matter who asks.'
        : `A co-owner is a second person with write access to ${repoName}. They look at changes to sensitive areas before a PR opens. It is about what the change touches, not about who asked for it.`;

    const isCard = variant === 'card';
    const approveButton = canApprove ? (
        <Button
            variant="primary"
            icon={<GitPullRequest className="size-3.5" />}
            pending={isApproving}
            pendingLabel="Approving…"
            disabled={action.busy}
            onClick={() => run('approve', routes.approve)}
            aria-describedby={decisionError !== null ? 'co-owner-error' : undefined}
            data-testid="co-owner-approve"
        >
            {isApproving ? 'Approving…' : isCard ? 'Approve and open the PR' : 'Approve & open PR'}
        </Button>
    ) : null;
    const canDecide = coOwner.canDecide !== false;
    const cardBody = cardSentences(coOwner, isRequesterView, holdSentence).join(' ');
    const dividers = isCard ? '' : 'border-b border-hair';
    const gutter = 'px-6';

    return (
        <section
            id="co-owner"
            aria-labelledby="co-owner-title"
            className={isCard ? 'rounded-card bg-warn-soft' : 'rounded-card border border-hair-strong bg-panel shadow-card'}
            data-testid={isCard ? 'card-co-owner' : 'co-owner-panel'}
        >
            <div className={`flex flex-wrap items-start justify-between gap-3 ${dividers} ${gutter} py-4`}>
                <div className="max-w-xl">
                    <h2 id="co-owner-title" className="text-[15px] font-semibold">{isCard ? 'Yak is waiting for a co-owner before it opens the pull request' : 'This change needs a co-owner'}</h2>
                    <p className="mt-0.5 text-[12.5px] leading-normal text-muted" data-testid="co-owner-explanation">{isCard ? cardBody : body}</p>
                </div>
                {!isCard && <Badge tone="warn">Held since {clock(coOwner.heldAt)} · expires in {daysLeft(coOwner.expiresAt)}</Badge>}
            </div>

            <div className={`${dividers} ${gutter} ${isCard ? 'pb-3' : 'py-5'}`}>
                {holdSentence !== null && !isCard ? <p className="text-[13px] leading-normal" data-testid="co-owner-hold-reason">{holdSentence}</p> : isCard ? <ChangedFiles coOwner={coOwner} onOpenChanges={onOpenChanges} /> : <AreaCards coOwner={coOwner} />}
            </div>

            {isRequesterView ? (
                coOwner.asked.length === 0 ? (
                    <NobodyAsked coOwner={coOwner} />
                ) : (
                    <Strip icon={<RefreshCw className="size-4" />} tone="neutral" title="Waiting for a co-owner">
                        {coOwner.asked.join(', ')} was asked. Anyone with write access to {repoName} can approve.
                    </Strip>
                )
            ) : null}

            {isRequesterView && (
                <p className="px-6 pb-4 text-[12.5px] leading-normal text-muted">
                    {coOwner.asked.length === 0
                        ? 'Share the link with someone who has write access, or withdraw and Yak deletes the branch.'
                        : 'Share the link if you know someone who can take a look. If you change your mind, withdraw the task and Yak deletes the branch.'}
                </p>
            )}

            {canDecide && !isRequesterView && feedbackOpen && (
                <div className="flex flex-col gap-1.5 border-b border-hair px-6 py-4" data-testid="co-owner-feedback">
                    <label htmlFor="co-owner-feedback-text" className="text-[13px] font-semibold">
                        What should Yak change? <span className="font-normal text-muted">Yak runs a follow-up with this and asks again here.</span>
                    </label>
                    <Textarea id="co-owner-feedback-text" aria-describedby="co-owner-feedback-hint" rows={3} maxLength={2000} value={feedback} onChange={(event) => setFeedback(event.target.value)} data-testid="co-owner-feedback-text" />
                    <p id="co-owner-feedback-hint" className="text-[12px] text-muted">No PR exists yet, so nothing is merged or visible to customers while Yak revises.</p>
                    <div className="flex justify-end gap-2">
                        <Button onClick={() => setFeedbackOpen(false)} disabled={action.busy}>Cancel</Button>
                        <Button
                            variant="primary"
                            pending={action.isPending('request-changes')}
                            disabled={feedback.trim() === '' || action.busy}
                            onClick={() => run('request-changes', routes.requestChanges, { feedback })}
                            data-testid="co-owner-send-feedback"
                        >
                            Send feedback to Yak
                        </Button>
                    </div>
                </div>
            )}

            {isApproving && (
                <Strip icon={<RefreshCw className="size-4 animate-spin" />} tone="neutral" title="Checking your write access and opening the PR.">
                    This usually takes a few seconds.
                </Strip>
            )}

            {canDecide && decisionError !== null && !isApproving && (
                <DecisionError error={decisionError} lastAction={lastAction} onRetry={() => run('approve', routes.approve)} busy={action.busy} />
            )}

            {canDecide && !isRequesterView && !canApprove && (
                <AccessPrompt coOwner={coOwner} busy={action.busy} onCheckAgain={() => run('check-access', routes.checkAccess)} checking={action.isPending('check-access')} />
            )}

            {canDecide && (
            <div className={`flex flex-wrap items-center justify-between gap-3 rounded-b-card px-6 py-3.5 ${isCard ? '' : 'bg-app'}`}>
                <span className="max-w-md text-[12px] text-muted">
                    {canApprove ? (
                        <>Write access to {repoName} confirmed as <code className="font-mono text-[11px]">{viewer.login}</code>. Approving makes you the responsible reviewer.</>
                    ) : viewer.access === 'read' && viewer.login !== null ? (
                        `Linked as ${viewer.login}. Access is checked live and cached for 10 minutes.`
                    ) : null}
                </span>
                <div className="ml-auto flex flex-wrap gap-2">
                    {isRequesterView ? (
                        <>
                            <Button onClick={() => run('withdraw', routes.withdraw)} pending={action.isPending('withdraw')} disabled={action.busy}>Withdraw</Button>
                            <Button icon={<LinkIcon className="size-3.5" />} onClick={copyLink}>{copied ? 'Link copied' : 'Copy link to share'}</Button>
                            {coOwner.asked.length === 0 && repoUrl !== null && (
                                <Button onClick={() => router.visit(repoUrl)}>Open repository page</Button>
                            )}
                        </>
                    ) : viewer.access !== 'read' || canApprove ? (
                        <>
                            {isCard && approveButton}
                            {!isCard && <Button onClick={() => setRejectOpen(true)} disabled={action.busy} data-testid="co-owner-reject">Reject</Button>}
                            <Button onClick={() => setFeedbackOpen((open) => !open)} disabled={action.busy} data-testid="co-owner-request-changes" aria-describedby={decisionError !== null ? 'co-owner-error' : undefined}>Request changes</Button>
                            {isCard && <Button onClick={() => setRejectOpen(true)} disabled={action.busy} data-testid="co-owner-reject">Reject</Button>}
                            {!isCard && approveButton}
                        </>
                    ) : null}
                </div>
            </div>
            )}

            <ConfirmDialog
                open={rejectOpen}
                onOpenChange={setRejectOpen}
                title="Reject this change?"
                body={`Yak deletes the branch ${coOwner.branch ?? ''} and no PR opens.`}
                confirmLabel="Reject"
                busy={action.isPending('reject')}
                onConfirm={() => run('reject', routes.reject, {}, () => setRejectOpen(false))}
                confirmTestId="co-owner-reject-confirm"
            />
        </section>
    );
}

function Strip({ icon, tone, title, children }: { icon: ReactNode; tone: 'neutral' | 'fail' | 'warn'; title: string; children?: ReactNode }) {
    const tint = tone === 'fail' ? 'bg-fail/10' : tone === 'warn' ? 'bg-warn/10' : 'bg-app';
    return (
        <div className="px-6 py-3.5">
            <div className={`flex items-start gap-3 rounded-control px-3.5 py-3 ${tint}`} role={tone === 'fail' ? 'alert' : undefined}>
                <span className={`mt-0.5 flex-none ${tone === 'fail' ? 'text-fail' : 'text-muted'}`}>{icon}</span>
                <div className="min-w-0 flex-1">
                    <div className="text-[13px] font-semibold">{title}</div>
                    {children !== undefined && <div className="mt-0.5 text-[12.5px] leading-normal">{children}</div>}
                </div>
            </div>
        </div>
    );
}

function AreaCards({ coOwner }: { coOwner: CoOwnerData }) {
    const { stat } = coOwner;
    return (
        <div className="flex flex-col gap-3">
            <div className="text-[11px] font-semibold uppercase tracking-wide text-faint">Matched area</div>
            {coOwner.areas.map((area) => (
                <div key={area.name} className="rounded-control border border-hair bg-app p-4" data-testid="co-owner-area">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-[14px] font-semibold">{area.name}</span>
                        <Badge tone={area.risk === 'critical' ? 'fail' : 'warn'}>{area.risk.charAt(0).toUpperCase() + area.risk.slice(1)} risk</Badge>
                    </div>
                    <div className="mt-3 text-[11px] font-semibold uppercase tracking-wide text-faint">Why this area is sensitive</div>
                    <p className="mt-1 text-[13px] leading-normal">{area.rationale}</p>
                    <ul className="mt-3 flex flex-col gap-1">
                        {area.files.map((file) => (
                            <li key={file.path} className="flex items-center gap-2 text-[12px]">
                                <code className="min-w-0 break-all font-mono">{file.path}</code>
                                <Badge tone="neutral">{file.status === 'new' ? 'new' : 'modified'}</Badge>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[12px]">
                <span>
                    <span className="text-ok">+{stat.additions}</span> <span className="text-fail">{MINUS}{stat.deletions}</span> across {stat.files} {stat.files === 1 ? 'file' : 'files'}
                </span>
                <span className="flex items-center gap-1 text-ok">
                    <Check className="size-3.5" />
                    CI passed
                </span>
                <a href={coOwner.compareUrl} target="_blank" rel="noreferrer" className="flex items-center gap-1 text-accent-text hover:underline">
                    View changes on GitHub <ExternalLink className="size-3" />
                </a>
            </div>
        </div>
    );
}

const LISTED_FILES = 3;

/** The first few changed files and a link to the rest, for the compact card. */
function ChangedFiles({ coOwner, onOpenChanges }: { coOwner: CoOwnerData; onOpenChanges?: () => void }) {
    const paths = coOwner.areas.flatMap((area) => area.files.map((file) => file.path));
    const listed = paths.slice(0, LISTED_FILES);
    const rest = Math.max(0, coOwner.stat.files - listed.length);

    return (
        <div className="flex flex-col gap-1" data-testid="co-owner-files">
            {listed.map((path) => (
                <code key={path} className="break-all font-mono text-[12px]">{path}</code>
            ))}
            {rest > 0 && (
                <button type="button" onClick={onOpenChanges} className="w-fit cursor-pointer text-left text-[12.5px] text-accent-text hover:underline" data-testid="co-owner-more-files">
                    and {rest} more {rest === 1 ? 'file' : 'files'} in Changes
                </button>
            )}
        </div>
    );
}

function NobodyAsked({ coOwner }: { coOwner: CoOwnerData }) {
    const rows: { label: string | null; text: string }[] = coOwner.attempts.map((attempt: CoOwnerAttempt) => {
        if (attempt.kind === 'codeowners') {
            return attempt.entry === 'owners'
                ? { label: null, text: 'Yak could not resolve the owners.' }
                : { label: 'CODEOWNERS', text: 'could not be read, so no owner was asked.' };
        }

        return { label: attemptLabel(attempt.kind), text: `${attempt.entry}. ${OUTCOME_TEXT[attempt.outcome] ?? attempt.outcome}` };
    });
    if (!coOwner.attempts.some((attempt) => attempt.kind === 'fallback')) {
        rows.push({ label: 'Fallback owner', text: `None set for ${coOwner.repoName}.` });
    }
    rows.push({ label: 'Requester', text: `${coOwner.requesterName} asked for this change, so Yak does not ask them to approve it.` });

    return (
        <div data-testid="co-owner-nobody">
            <Strip icon={<UserX className="size-4" />} tone="warn" title="Nobody can be asked yet">
                Yak could not find a co-owner for this change. It stays held until one is available.
            </Strip>
            <div className="px-6 pb-4">
                <div className="text-[11px] font-semibold uppercase tracking-wide text-faint">Who Yak tried to ask</div>
                <ul className="mt-2 flex flex-col gap-2">
                    {rows.map((row, index) => (
                        <li key={index} className="flex items-start gap-2.5 text-[12.5px]">
                            <span aria-hidden className="mt-1.5 size-2 flex-none rounded-full bg-hair-strong" />
                            <span>
                                {row.label !== null && <b>{row.label} </b>}
                                <span className="text-muted">{row.text}</span>
                            </span>
                        </li>
                    ))}
                </ul>
                <div className="mt-4 text-[11px] font-semibold uppercase tracking-wide text-faint">How to fix it</div>
                <p className="mt-1 text-[13px] leading-normal">
                    A repository admin can add owners who have linked GitHub to CODEOWNERS, or set a fallback owner in {coOwner.repoName}&apos;s repository settings in Yak. Anyone with write access can also approve this task directly from this page. Yak asks again as soon as an owner resolves.
                </p>
            </div>
        </div>
    );
}

function DecisionError({ error, lastAction, onRetry, busy }: { error: CoOwnerDecisionError; lastAction: string | null; onRetry: () => void; busy: boolean }) {
    const isApproveFailure = error.reason === 'github_failed' && lastAction === 'approve';

    return (
        <div id="co-owner-error" data-testid="co-owner-error">
            <Strip icon={<AlertTriangle className="size-4" />} tone="fail" title={isApproveFailure ? 'Could not open the PR.' : error.message}>
                {isApproveFailure && (
                    <>
                        GitHub rejected the request. Nothing was merged and the branch is untouched, so it is safe to try again.
                        <div className="mt-1 font-mono text-[11px] text-muted">{[error.status, error.message].filter((part) => part !== null).join(' ')}</div>
                    </>
                )}
            </Strip>
            {isApproveFailure && (
                <div className="flex justify-end px-6 pb-3">
                    <Button icon={<RefreshCw className="size-3.5" />} onClick={onRetry} disabled={busy}>Retry approve</Button>
                </div>
            )}
        </div>
    );
}

function LinkFailure({ error }: { error: CoOwnerLinkError }) {
    return error.kind === 'taken' ? (
        <>
            <b>This GitHub account is already linked.</b>
            <p>
                {error.ownerLogin} is linked to {error.ownerName} in Yak. Each GitHub account can belong to one Yak user. Ask {firstName(error.ownerName ?? '')} to disconnect it on their profile, or sign in to GitHub with your own account.
            </p>
        </>
    ) : (
        <>
            <b>Could not link GitHub.</b>
            <p>GitHub sent you back without finishing the sign-in, so Yak cannot check your access. Nothing was approved.</p>
            {error.code !== null && <code className="font-mono text-[11px] text-muted">{error.code}</code>}
        </>
    );
}

function AccessPrompt({ coOwner, busy, onCheckAgain, checking }: { coOwner: CoOwnerData; busy: boolean; onCheckAgain: () => void; checking: boolean }) {
    const { viewer, repoName } = coOwner;

    if (viewer.access === 'unlinked') {
        return (
            <div className="px-6 py-3.5" data-testid="co-owner-connect">
                <div className={`flex flex-wrap items-center justify-between gap-3 rounded-control px-3.5 py-3 ${coOwner.linkError !== null ? 'bg-fail/10' : 'bg-app'}`}>
                    <div className="text-[12.5px] leading-normal">
                        {coOwner.linkError !== null ? (
                            <LinkFailure error={coOwner.linkError} />
                        ) : (
                            <>Connect GitHub to approve. Yak checks you have write access to {repoName}.</>
                        )}
                    </div>
                    <Button variant="primary" onClick={() => window.location.assign(coOwner.connectUrl)}>
                        {coOwner.linkError !== null ? 'Try again' : 'Connect GitHub'}
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div className="px-6 py-3.5" data-testid="co-owner-no-access">
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-control bg-warn/10 px-3.5 py-3">
                <div className="text-[12.5px] leading-normal">
                    {viewer.access === 'error' ? (
                        <b>Yak could not check your access on GitHub. Try again in a minute.</b>
                    ) : (
                        <>
                            <b>Your GitHub account has no write access to {repoName}.</b>
                            <p>
                                You are linked as {viewer.login} with read access. Only people with write access can approve. Ask a repository admin to grant it, or hand this to someone who has it.
                            </p>
                        </>
                    )}
                </div>
                <Button onClick={onCheckAgain} pending={checking} disabled={busy}>Check again</Button>
            </div>
        </div>
    );
}
