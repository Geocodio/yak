import { Button } from '@geocodio/console-ui';
import { Link, router } from '@inertiajs/react';
import { KindIcon } from '@/components/threads/stack/KindIcon';
import { StatePill } from '@/components/threads/stack/StatePill';
import { show } from '@/routes/threads';
import { drop, split, takeOver } from '@/routes/threads/stack';
import type { StackChild } from '@/types/stack';

export function ChildRow({ child, onChanged }: { child: StackChild; onChanged: () => void }) {
    const act = (route: typeof takeOver) => router.post(route.url(child.id), {}, { preserveScroll: true, onFinish: onChanged });
    const detail = child.state === 'working' ? child.step : child.state === 'done' ? child.resultSummary : null;

    return (
        <li className="px-3 py-2.5" data-testid={`stack-child-${child.id}`} data-state={child.state}>
            <div className="flex items-center gap-2">
                <KindIcon kind={child.kind} />
                <Link href={show.url(child.id)} className="min-w-0 truncate text-[13px] font-medium text-body hover:underline">{child.name}</Link>
                <span className="ml-auto">
                    <StatePill state={child.state} label={child.takenOver ? 'Taken over' : undefined} />
                </span>
            </div>
            <p className="mt-0.5 text-[12px] text-muted">{child.kind}{child.model ? ` · ${child.model}` : ''}{child.messagesPaused ? ' · messages paused' : ''}</p>
            {child.repo && (
                <p className="mt-0.5 text-[12px] text-muted" data-testid={`stack-child-${child.id}-repo`}>
                    {child.repo}
                    {child.pullRequestUrl && (
                        <>
                            {' · '}
                            <a href={child.pullRequestUrl} target="_blank" rel="noreferrer" className="hover:underline" data-testid={`stack-child-${child.id}-pr`}>
                                Pull request{child.ciStatus ? ` (${child.ciStatus})` : ''}
                            </a>
                        </>
                    )}
                </p>
            )}
            {detail && <p className="mt-1 line-clamp-2 text-[12px] text-muted">{detail}</p>}
            {child.canOverride && !child.takenOver && (
                <div className="mt-2 flex flex-wrap gap-2">
                    <Button variant="secondary" onClick={() => act(takeOver)} data-testid={`stack-child-${child.id}-take-over`}>Take it over</Button>
                    <Button variant="secondary" onClick={() => act(split)}>Split it up</Button>
                    <Button variant="tertiary" onClick={() => act(drop)}>Drop it</Button>
                </div>
            )}
        </li>
    );
}
