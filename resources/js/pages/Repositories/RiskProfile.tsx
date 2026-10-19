import { Deferred, Head } from '@inertiajs/react';
import { Badge, Button, PageHeader } from '@geocodio/console-ui';
import { ExternalLink, Lock, Pencil } from 'lucide-react';
import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import {
    EmptyState,
    FailedState,
    GeneratingState,
    LoadingState,
    NeverValidState,
    UnavailableState,
    WaitingState,
} from '@/components/riskProfile/RiskProfileStates';
import type { PageProps } from '@/types/shared';
import type { RiskProfilePage, RiskProfileRepository } from '@/types/riskProfile';

type Props = PageProps<{
    repository: RiskProfileRepository;
    riskProfile?: RiskProfilePage;
}>;

const FILE = '.yak/risk-profile.yml';

function shortDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' });
}

function sourceLine(data: RiskProfilePage, branch: string): ReactNode {
    const file = data.file;
    const code = 'font-mono text-text';

    if (data.state === 'unavailable') {
        return null;
    }

    if (data.state === 'never-valid') {
        return (
            <>
                <span className={code}>{FILE}</span> on {branch}
                {file?.errorCommitSha && <> · <span className={code}>{file.errorCommitSha.slice(0, 7)}</span></>}
            </>
        );
    }

    if (data.state === 'empty') {
        return (
            <>
                No <span className={code}>{FILE}</span> on {branch}
                {data.generation && data.generation.status !== 'failed' ? ' yet' : ''}
            </>
        );
    }

    if (data.source === 'database') {
        return (
            <>
                From the approved profile stored in Yak. No <span className={code}>{FILE}</span> on {branch} yet.
            </>
        );
    }

    const changedIn = file?.changedIn ?? null;
    const sha = changedIn?.sha ?? file?.validCommitSha ?? null;

    return (
        <>
            From <span className={code}>{FILE}</span> on {branch}
            {sha && <> · <span className={code}>{sha.slice(0, 7)}</span></>}
            {changedIn && (
                <>
                    {' '}
                    · changed{changedIn.pullRequest && <> in #{changedIn.pullRequest.number}</>} by {changedIn.authorName}, {shortDate(changedIn.date)}
                </>
            )}
        </>
    );
}

function Shell({ repository, data, children }: { repository: RiskProfileRepository; data: RiskProfilePage | null; children: ReactNode }) {
    const file = data?.file ?? null;
    const source = data === null ? <>Reading <span className="font-mono text-text">{FILE}</span> from {repository.defaultBranch}…</> : sourceLine(data, repository.defaultBranch);
    const githubUrl = file?.blobUrl ?? repository.githubUrl;

    return (
        <>
            <Head title={`Risk profile, ${repository.slug}`} />
            <PageHeader crumbs={['Repositories', repository.slug, 'Risk profile']} />
            <div className="mx-auto w-full max-w-[1200px] px-6 py-6">
                <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="flex flex-wrap items-center gap-2 text-[20px] font-semibold">
                            Risk profile
                            <span className="text-[14px] font-normal text-muted">{repository.slug}</span>
                            <Badge tone="neutral">
                                <Lock size={11} className="mr-1 inline" />
                                Read-only
                            </Badge>
                        </h1>
                        {source && <p data-testid="risk-profile-source" className="mt-1 text-[12px] text-muted">{source}</p>}
                    </div>
                    {file && (
                        <div className="flex gap-2">
                            {githubUrl && (
                                <a href={githubUrl} target="_blank" rel="noopener noreferrer">
                                    <Button icon={<ExternalLink size={13} />}>View on GitHub</Button>
                                </a>
                            )}
                            {file.editUrl && (
                                <a href={file.editUrl} target="_blank" rel="noopener noreferrer">
                                    <Button icon={<Pencil size={13} />}>Propose a change</Button>
                                </a>
                            )}
                        </div>
                    )}
                </div>
                {children}
            </div>
        </>
    );
}

function Body({ data, repository }: { data: RiskProfilePage; repository: RiskProfileRepository }) {
    if (data.state === 'unavailable') {
        return <UnavailableState />;
    }

    const generation = data.generation;
    let body: ReactNode = null;

    if (data.state === 'never-valid') {
        body = <NeverValidState file={data.file} repository={repository} />;
    } else if (data.state === 'empty') {
        if (generation?.status === 'running') {
            body = <GeneratingState generation={generation} />;
        } else if (generation?.status === 'waiting') {
            body = <WaitingState generation={generation} />;
        } else if (generation?.status === 'failed') {
            body = <FailedState generation={generation} repository={repository} />;
        } else {
            body = <EmptyState repository={repository} />;
        }
    } else {
        // Replaced by ProfileView.
        body = <div data-testid="risk-profile-view" className="min-h-16" />;
    }

    return body;
}

export default function RiskProfile({ repository, riskProfile }: Props) {
    return (
        <Deferred
            data="riskProfile"
            fallback={
                <Shell repository={repository} data={null}>
                    <LoadingState branch={repository.defaultBranch} />
                </Shell>
            }
        >
            {riskProfile ? (
                <Shell repository={repository} data={riskProfile}>
                    <Body data={riskProfile} repository={repository} />
                </Shell>
            ) : null}
        </Deferred>
    );
}

RiskProfile.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
