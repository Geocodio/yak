import { Badge, Button } from '@geocodio/console-ui';
import { useForm } from '@inertiajs/react';
import { FilePen, ShieldAlert, Sparkles } from 'lucide-react';
import { Callout } from '@/components/riskProfile/RiskProfileStates';
import type { ApprovalConcerns, ApprovalGithub, ApprovalsRepository } from '@/types/approvals';

const FILE = '.yak/risk-profile.yml';

function DraftConcernsButton({ url }: { url: string }) {
    const form = useForm({ action: 'generate' });

    return (
        <Button data-testid="approvals-draft-concerns" variant="primary" icon={<Sparkles size={14} />} pending={form.processing} onClick={() => form.post(url)}>
            Draft concerns
        </Button>
    );
}

export function ProtectedAssets({ repository, concerns, github }: { repository: ApprovalsRepository; concerns: ApprovalConcerns; github: ApprovalGithub | undefined }) {
    const pullRequest = github?.concernsPullRequest ?? null;
    const isReady = concerns.state === 'ready';

    return (
        <section data-testid="approvals-concerns" aria-labelledby="approvals-assets" className="flex flex-col gap-3.5 rounded-card border border-hair bg-panel p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 flex-1 basis-[320px]">
                    <h2 id="approvals-assets" className="font-mono text-[13px] tracking-[0.18em]">PROTECTED ASSETS ({concerns.items.length})</h2>
                    <p className="mt-1 text-[14px] leading-snug text-muted">
                        The critical concerns in <code className="font-mono text-body">{FILE}</code>, in plain language. Unlike the lever, this list lives in the codebase and changes through a pull request. A separate check reads only the diff and sends any PR that touches one to a human, at every level.
                    </p>
                </div>
                {isReady && concerns.editFileUrl && (
                    <a href={concerns.editFileUrl} target="_blank" rel="noopener noreferrer">
                        <Button icon={<FilePen size={14} />}>Edit in a pull request</Button>
                    </a>
                )}
            </div>
            {isReady ? (
                <ul className="grid grid-cols-[repeat(auto-fit,minmax(300px,1fr))] gap-2.5">
                    {concerns.items.map((concern) => (
                        <li key={concern.name} className="flex flex-col gap-1.5 rounded-control border border-hair bg-panel-2 px-4 py-3.5">
                            <span className="flex flex-wrap items-center gap-2 text-[14.5px] font-semibold">
                                <ShieldAlert size={15} aria-hidden="true" className="shrink-0 text-fail" />
                                {concern.name}
                                <Badge tone={concern.approval === 'always_human' ? 'fail' : 'info'}>{concern.approval === 'always_human' ? 'Always a human' : 'Approvable when tested'}</Badge>
                            </span>
                            <span className="text-[13px] leading-snug text-muted">{concern.description}</span>
                        </li>
                    ))}
                </ul>
            ) : (
                <Callout tone={concerns.state === 'invalid' ? 'fail' : 'neutral'} icon={<ShieldAlert size={16} className="text-muted" />}>
                    <p>
                        {concerns.state === 'invalid'
                            ? <>The concerns file on {repository.defaultBranch} has errors. Yak approves nothing until it is fixed.</>
                            : <>No version 2 concerns file on {repository.defaultBranch}. Yak approves nothing until one is merged.</>}
                    </p>
                    <DraftConcernsButton url={repository.draftConcernsUrl} />
                </Callout>
            )}
            {pullRequest && (
                <Callout tone="warn" icon={<span aria-hidden="true" className="mt-1.5 block size-2 animate-pulse rounded-full bg-warn" />}>
                    <p>
                        <strong>An edit to this list is waiting in PR #{pullRequest.number}.</strong> Yak keeps using the list on <code className="font-mono">{repository.defaultBranch}</code> until a human merges it.{' '}
                        <a className="font-semibold underline" href={pullRequest.url} target="_blank" rel="noopener noreferrer">View PR #{pullRequest.number}</a>
                    </p>
                </Callout>
            )}
        </section>
    );
}
