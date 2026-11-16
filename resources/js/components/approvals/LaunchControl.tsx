import { Skeleton, Toggle } from '@geocodio/console-ui';
import { Check, Eye, Lock, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { Callout } from '@/components/riskProfile/RiskProfileStates';
import type { ApprovalConcerns, ApprovalGithub, ApprovalLevel, ApprovalShadow, ApprovalsRepository } from '@/types/approvals';
import { ARM_HELP } from './levels';

type Kind = 'ok' | 'blocked' | 'manual';

const ICON_SURFACE: Record<Kind, string> = {
    ok: 'border-hair bg-ok-soft text-ok',
    blocked: 'border-hair bg-warn-soft text-warn',
    manual: 'border-hair-strong bg-panel-2 text-muted',
};

function PreflightRow({ kind, label, status, note }: { kind: Kind; label: string; status: string; note: ReactNode }) {
    const Icon = kind === 'ok' ? Check : kind === 'blocked' ? X : Eye;
    const statusColor = kind === 'ok' ? 'text-ok' : kind === 'blocked' ? 'text-warn' : '';

    return (
        <li className="flex items-start gap-2.5">
            <span aria-hidden="true" className={`mt-px flex size-[22px] shrink-0 items-center justify-center rounded-full border ${ICON_SURFACE[kind]}`}>
                <Icon size={13} />
            </span>
            <span className="flex min-w-0 flex-col">
                <span className="text-[14px] font-semibold leading-snug">{label}</span>
                <span className="text-[13px] leading-snug text-muted">
                    <strong className={`font-semibold ${statusColor}`}>{status}</strong> {note}
                </span>
            </span>
        </li>
    );
}

export function LaunchControl({ repository, github, concerns, shadow, draftLevel, isArmed, onArmedChange, children }: {
    repository: ApprovalsRepository;
    github: ApprovalGithub | undefined;
    concerns: ApprovalConcerns;
    shadow: ApprovalShadow;
    draftLevel: ApprovalLevel;
    isArmed: boolean;
    onArmedChange: (armed: boolean) => void;
    children?: ReactNode;
}) {
    const isOff = draftLevel === 1;
    const checks = github?.requiredChecks;
    const isLocked = checks !== 'present';
    const armed = isArmed && !isOff;
    const isDisabled = isOff || (isLocked && !armed);
    const help = isOff ? ARM_HELP.off : armed ? ARM_HELP.armed : isLocked ? ARM_HELP.locked : ARM_HELP.shadow;
    const days = shadow.firstRecordedAt ? Math.floor((Date.now() - new Date(shadow.firstRecordedAt).getTime()) / 86_400_000) : 0;
    const branch = repository.defaultBranch;

    return (
        <section data-testid="approvals-arm" aria-labelledby="approvals-arm-heading" className="flex min-w-0 flex-1 basis-[300px] flex-col gap-4 rounded-card border border-hair bg-panel p-5">
            <div>
                <h2 id="approvals-arm-heading" className="font-mono text-[13px] tracking-[0.18em]">LAUNCH CONTROL</h2>
                <p className="mt-1 text-[14px] text-muted">Shadow records what Yak would approve. Armed submits real approvals.</p>
            </div>
            <div className="flex items-center justify-center gap-3.5 rounded-card border border-hair bg-panel-2 p-3.5">
                <span className={`font-mono text-[12px] font-bold tracking-[0.14em] ${armed ? 'text-muted' : ''}`}>SHADOW</span>
                <Toggle label="Armed" checked={armed} disabled={isDisabled} onCheckedChange={onArmedChange} data-testid="approvals-arm-toggle" />
                <span className={`font-mono text-[12px] font-bold tracking-[0.14em] ${armed ? 'text-fail' : 'text-muted'}`}>ARMED</span>
            </div>
            <p className="text-[14px] leading-snug">{help}</p>
            {github === undefined ? (
                <Skeleton className="h-16 w-full rounded-card" />
            ) : checks === 'missing' ? (
                <div data-testid="approvals-arm-locked">
                    <Callout tone="warn" icon={<Lock size={16} className="text-warn" />}>
                        <p>
                            <strong>Arming is locked.</strong> <code className="font-mono">{branch}</code> has no required status checks, so an approval from Yak could clear a PR with failing CI. Add at least one required check.{' '}
                            {repository.githubUrl && (
                                <a className="font-semibold underline" href={`${repository.githubUrl}/settings/branches`} target="_blank" rel="noopener noreferrer">Branch protection on GitHub</a>
                            )}
                        </p>
                    </Callout>
                </div>
            ) : checks === 'unknown' ? (
                <div data-testid="approvals-arm-locked">
                    <Callout tone="warn" icon={<Lock size={16} className="text-warn" />}>
                        <p><strong>Arming is locked.</strong> Could not check branch protection on GitHub.</p>
                    </Callout>
                </div>
            ) : null}
            <div className="flex flex-col gap-2">
                <h3 className="font-mono text-[12px] tracking-[0.16em]">PREFLIGHT: IS IT SAFE TO ARM?</h3>
                <ul className="flex flex-col gap-2">
                    {checks === 'present' && <PreflightRow kind="ok" label={`Required status checks on ${branch}`} status="Present." note="CI has to pass before anything can merge." />}
                    {checks === 'missing' && <PreflightRow kind="blocked" label={`Required status checks on ${branch}`} status="Missing." note="Arming stays locked until there is at least one." />}
                    {checks === 'unknown' && <PreflightRow kind="blocked" label={`Required status checks on ${branch}`} status="Unknown." note="Could not check branch protection on GitHub." />}
                    {checks === undefined && <PreflightRow kind="manual" label={`Required status checks on ${branch}`} status="Checking." note="Asking GitHub." />}
                    {concerns.state === 'ready' ? (
                        <PreflightRow kind="ok" label={`Protected assets file on ${branch}`} status="Ready." note={`${concerns.items.length} ${concerns.items.length === 1 ? 'concern' : 'concerns'} listed.`} />
                    ) : (
                        <PreflightRow kind="blocked" label={`Protected assets file on ${branch}`} status="Missing." note="Yak approves nothing until a version 2 file is merged." />
                    )}
                    <PreflightRow kind="manual" label="Two weeks of shadow results" status="Your call." note={`${days} days recorded so far.`} />
                    <PreflightRow kind="manual" label="No would-be approval touched a protected asset" status="Your call." note={`${shadow.wouldApprove} would-be approvals to read in the shadow log.`} />
                </ul>
            </div>
            {children}
        </section>
    );
}
