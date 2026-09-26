import { Head, router } from '@inertiajs/react';
import { Badge, Button, PageHeader, StackedTable, StackedTbody, StackedTd, StackedThead, StackedTr, StatusPill, Th, Tr } from '@geocodio/console-ui';
import { Plus, Star } from 'lucide-react';
import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import repos from '@/routes/repos';
import type { RepositorySummary } from '@/types/repositories';
import type { PageProps } from '@/types/shared';

type Props = PageProps<{
    repositories: RepositorySummary[];
}>;

const SETUP_TONE: Record<string, 'ok' | 'warn' | 'fail' | 'info' | 'idle'> = {
    ready: 'ok',
    running: 'info',
    pending: 'idle',
    failed: 'fail',
};

function setupTone(status: string): 'ok' | 'warn' | 'fail' | 'info' | 'idle' {
    return SETUP_TONE[status] ?? 'idle';
}

export default function Index({ repositories }: Props) {
    return (
        <>
            <Head title="Repositories" />
            <PageHeader
                crumbs={['Repositories']}
                actions={
                    <Button variant="primary" icon={<Plus size={13} />} onClick={() => router.visit(repos.create.url())}>
                        Add repository
                    </Button>
                }
            />

            <div className="min-h-0 flex-1 overflow-auto">
                {repositories.length > 0 ? (
                    <StackedTable className="w-full">
                        <StackedThead>
                            <Tr>
                                <Th>Slug</Th>
                                <Th>Name</Th>
                                <Th>CI system</Th>
                                <Th>Setup</Th>
                                <Th>Base</Th>
                                <Th>Status</Th>
                                <Th>Default</Th>
                                <Th className="text-right">Tasks (total)</Th>
                                <Th className="text-right">Tasks (7d)</Th>
                                <Th>PR review</Th>
                            </Tr>
                        </StackedThead>
                        <StackedTbody>
                            {repositories.map((repo) => (
                                <StackedTr
                                    key={repo.slug}
                                    interactive
                                    data-testid={`repo-row-${repo.slug}`}
                                    onClick={() => router.visit(repos.edit.url(repo.slug))}
                                    className={repo.isActive ? undefined : 'opacity-60'}
                                >
                                    <StackedTd label="Slug" className="font-medium text-accent">
                                        {repo.slug}
                                    </StackedTd>
                                    <StackedTd label="Name" className="text-muted">
                                        {repo.name}
                                    </StackedTd>
                                    <StackedTd label="CI system" className="text-muted">
                                        {repo.ciLabel}
                                    </StackedTd>
                                    <StackedTd label="Setup">
                                        <StatusPill tone={setupTone(repo.setupStatus)} label={ucfirst(repo.setupStatus)} pulse={repo.setupStatus === 'running'} />
                                    </StackedTd>
                                    <StackedTd label="Base">
                                        {repo.sandboxBaseVersion === null ? (
                                            <span className="text-faint" title="No sandbox template provisioned yet">
                                                &mdash;
                                            </span>
                                        ) : repo.sandboxBaseVersion === repo.currentBaseVersion ? (
                                            <Badge tone="ok" className="tnum">
                                                v{repo.sandboxBaseVersion}
                                            </Badge>
                                        ) : (
                                            <Badge tone="warn" className="tnum" title={`Template drift -- current yak-base is v${repo.currentBaseVersion}. Next task run will re-provision.`}>
                                                v{repo.sandboxBaseVersion} &rarr; v{repo.currentBaseVersion}
                                            </Badge>
                                        )}
                                    </StackedTd>
                                    <StackedTd label="Status">
                                        <StatusPill tone={repo.isActive ? 'ok' : 'idle'} label={repo.isActive ? 'Active' : 'Inactive'} />
                                    </StackedTd>
                                    <StackedTd label="Default">
                                        {repo.isDefault ? <Star size={14} className="fill-accent text-accent" /> : <span className="text-faint">&mdash;</span>}
                                    </StackedTd>
                                    <StackedTd label="Tasks (total)" className={cnCount(repo.tasksTotal)}>
                                        {repo.tasksTotal}
                                    </StackedTd>
                                    <StackedTd label="Tasks (7d)" className={cnCount(repo.tasks7d)}>
                                        {repo.tasks7d}
                                    </StackedTd>
                                    <StackedTd label="PR review">
                                        {repo.prReviewEnabled ? (
                                            <div className="flex flex-col gap-0.5">
                                                <Badge tone="ok" className="w-fit">
                                                    On
                                                </Badge>
                                                {repo.prReviews30d > 0 && <span className="text-[11px] text-muted">{repo.prReviews30d} in 30d</span>}
                                            </div>
                                        ) : (
                                            <span className="text-faint">&mdash;</span>
                                        )}
                                    </StackedTd>
                                </StackedTr>
                            ))}
                        </StackedTbody>
                    </StackedTable>
                ) : (
                    <div className="flex flex-col items-center gap-3 px-4 py-16 text-center text-[13px] text-muted sm:px-5">
                        <p>No repositories yet. Add one so Yak can clone and work on it.</p>
                    </div>
                )}
            </div>
        </>
    );
}

function cnCount(value: number): string {
    return ['tnum md:text-right', value === 0 ? 'text-faint' : 'text-muted'].join(' ');
}

function ucfirst(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
