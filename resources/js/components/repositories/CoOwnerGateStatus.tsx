import { Badge, Button, Skeleton } from '@geocodio/console-ui';
import { ExternalLink, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import type { CoOwnerGateStatus as Status } from '@/types/repositories';

const DOT = { ok: 'bg-ok', warn: 'bg-warn' } as const;

function Row({ tone, title, children }: { tone: keyof typeof DOT; title: string; children: ReactNode }) {
    return (
        <div className="flex items-start gap-2.5" data-testid="co-owner-gate-row">
            <span className={`mt-1.5 size-2 shrink-0 rounded-full ${DOT[tone]}`} />
            <div className="min-w-0 text-[12px]">
                <div className="text-[13px] font-medium">{title}</div>
                <div className="text-muted">{children}</div>
            </div>
        </div>
    );
}

function Mono({ children }: { children: ReactNode }) {
    return <span className="font-mono">{children}</span>;
}

function ownerNames(names: string[]): string {
    const rest = names.length - 2;

    return rest > 0 ? `${names.slice(0, 2).join(', ')} and ${rest} more` : names.join(' and ');
}

export function CoOwnerGateStatusSkeleton({ branch }: { branch: string }) {
    return (
        <div data-testid="co-owner-gate-loading" className="space-y-2 rounded-card border border-hair bg-panel-2 p-4">
            <div className="text-[13px] font-medium">
                Reading the co-owner gate from <span className="font-mono">.yak/config.yml</span> on {branch}…
            </div>
            <Skeleton className="h-3 w-2/3" />
        </div>
    );
}

export function CoOwnerGateStatus({ status, branch }: { status: Status; branch: string }) {
    const isEnforced = status.mode === 'enforce';
    const { owners, profile, fallbackOwner } = status;
    const resolvable = owners.resolvable.length;
    const hasTeamsSkipped = isEnforced && owners.teamsSkipped.length > 0;
    const hasNoOneToAsk = isEnforced && resolvable === 0 && !fallbackOwner?.resolves;
    const isHealthy = isEnforced && !hasNoOneToAsk && !hasTeamsSkipped;
    const callout = hasNoOneToAsk
        ? {
              title: 'Enforce is on, but nobody can be asked.',
              body: (
                  <>
                      Held tasks in this repository stay held until an owner resolves. The <Mono>yak / config</Mono> check warns about this too.
                  </>
              ),
          }
        : hasTeamsSkipped
          ? {
                title: `${owners.teamsSkipped.length} team ${owners.teamsSkipped.length === 1 ? 'entry was' : 'entries were'} skipped.`,
                body: (
                    <>
                        The GitHub App lacks the Organization Members read permission, so Yak cannot expand{' '}
                        {owners.teamsSkipped.map((team, index) => (
                            <span key={team}>
                                {index > 0 && (index === owners.teamsSkipped.length - 1 ? ' and ' : ', ')}
                                <Mono>{team}</Mono>
                            </span>
                        ))}
                        . Individual owners still resolve.
                    </>
                ),
            }
          : null;

    return (
        <div data-testid="co-owner-gate-status" className="space-y-3 rounded-card border border-hair bg-panel-2 p-4">
            <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-[13px] font-semibold">Co-owner gate</h3>
                <Badge tone={isEnforced ? (isHealthy ? 'ok' : 'warn') : 'neutral'}>{isEnforced ? 'Enforce' : 'Off'}</Badge>
                <span className="ml-auto text-[12px] text-muted">
                    Read from <Mono>.yak/config.yml</Mono> on {branch}
                </span>
            </div>

            {!isEnforced && (
                <div className="rounded-control bg-panel p-3 text-[12px]">
                    <div className="text-[13px] font-medium">The gate is off for this repository.</div>
                    <p className="text-muted">
                        Set <Mono>co_owner_gate.mode: enforce</Mono> in <Mono>.yak/config.yml</Mono> and merge it to turn it on. Held-change checks are not run while it is off.
                    </p>
                </div>
            )}

            {callout && (
                <div data-testid="co-owner-gate-callout" className="flex flex-wrap items-start gap-3 rounded-card border border-hair bg-warn-soft p-3">
                    <TriangleAlert size={16} className="mt-0.5 shrink-0 text-warn" />
                    <div className="min-w-0 flex-1 space-y-1">
                        <div className="text-[13px] font-medium">{callout.title}</div>
                        <p className="text-[12px] text-muted">{callout.body}</p>
                    </div>
                    {hasTeamsSkipped && status.appSettingsUrl && (
                        <a href={status.appSettingsUrl ?? undefined} target="_blank" rel="noopener noreferrer">
                            <Button icon={<ExternalLink size={13} />}>GitHub App settings</Button>
                        </a>
                    )}
                </div>
            )}

            <div className="space-y-2.5">
                {profile ? (
                    <Row tone="ok" title={isEnforced ? 'Risk profile present' : 'Risk profile'}>
                        {profile.path ? <Mono>{profile.path}</Mono> : 'The approved profile stored in Yak'} on {branch}, {profile.areaCount} {profile.areaCount === 1 ? 'area' : 'areas'}.
                        {!isEnforced && ' Not used while the gate is off.'}
                    </Row>
                ) : (
                    <Row tone="warn" title="No risk profile">
                        {isEnforced ? 'Every change from a requester without write access is held.' : 'Not used while the gate is off.'}
                    </Row>
                )}

                {!isEnforced ? (
                    <Row tone="ok" title="Owners">
                        {resolvable} CODEOWNERS {resolvable === 1 ? 'owner would resolve' : 'owners would resolve'}.
                    </Row>
                ) : hasNoOneToAsk || resolvable === 0 ? (
                    <Row tone="warn" title="No owners resolve">
                        {owners.resolvedEntries} of {owners.total} CODEOWNERS owners are linked users with write access.
                    </Row>
                ) : hasTeamsSkipped ? (
                    <Row tone="warn" title="Owners partly resolvable">
                        {owners.resolvedEntries} of {owners.total} CODEOWNERS owners {owners.resolvedEntries === 1 ? 'resolves' : 'resolve'}: {ownerNames(owners.resolvable)}. {owners.teamsSkipped.length} {owners.teamsSkipped.length === 1 ? 'team' : 'teams'} skipped.
                    </Row>
                ) : (
                    <Row tone="ok" title="Owners resolvable">
                        {resolvable} CODEOWNERS {resolvable === 1 ? 'owner resolves' : 'owners resolve'} to linked users with write access: {ownerNames(owners.resolvable)}.
                    </Row>
                )}

                {isEnforced &&
                    (fallbackOwner?.resolves ? (
                        <Row tone="ok" title="Fallback owner">
                            {fallbackOwner.name}, {hasTeamsSkipped ? 'asked when teams cannot be expanded' : 'used when CODEOWNERS gives nobody usable'}.
                        </Row>
                    ) : (
                        <Row tone="warn" title="No fallback owner">
                            Add owners to CODEOWNERS or set a fallback owner in this page's Automation section.
                        </Row>
                    ))}
            </div>
        </div>
    );
}
