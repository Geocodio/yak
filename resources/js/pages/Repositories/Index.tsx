import { Head, router } from '@inertiajs/react';
import { Badge, Button, StatusPill, Table, Tbody, Td, Th, Thead, Tr } from '@geocodio/console-ui';
import { Plus, Star } from 'lucide-react';
import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { PageHeader } from '@/components/PageHeader';
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
                    <Table className="w-full">
                        <Thead>
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
                        </Thead>
                        <Tbody>
                            {repositories.map((repo) => (
                                <Tr
                                    key={repo.slug}
                                    interactive
                                    data-testid={`repo-row-${repo.slug}`}
                                    onClick={() => router.visit(repos.edit.url(repo.slug))}
                                    className={repo.isActive ? undefined : 'opacity-60'}
                                >
                                    <Td className="font-medium text-accent">{repo.slug}</Td>
                                    <Td className="text-muted">{repo.name}</Td>
                                    <Td className="text-muted">{repo.ciLabel}</Td>
                                    <Td>
                                        <StatusPill tone={setupTone(repo.setupStatus)} label={ucfirst(repo.setupStatus)} pulse={repo.setupStatus === 'running'} />
                                    </Td>
                                    <Td>
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
                                    </Td>
                                    <Td>
                                        <StatusPill tone={repo.isActive ? 'ok' : 'idle'} label={repo.isActive ? 'Active' : 'Inactive'} />
                                    </Td>
                                    <Td>
                                        {repo.isDefault ? <Star size={14} className="fill-accent text-accent" /> : <span className="text-faint">&mdash;</span>}
                                    </Td>
                                    <Td className={cnCount(repo.tasksTotal)}>{repo.tasksTotal}</Td>
                                    <Td className={cnCount(repo.tasks7d)}>{repo.tasks7d}</Td>
                                    <Td>
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
                                    </Td>
                                </Tr>
                            ))}
                        </Tbody>
                    </Table>
                ) : (
                    <div className="flex flex-col items-center gap-3 px-5 py-16 text-center text-[13px] text-muted">
                        <p>No repositories yet. Add one so Yak can clone and work on it.</p>
                    </div>
                )}
            </div>
        </>
    );
}

function cnCount(value: number): string {
    return ['tnum text-right', value === 0 ? 'text-faint' : 'text-muted'].join(' ');
}

function ucfirst(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
