import { Skeleton } from '@geocodio/console-ui';
import { useEffect } from 'react';
import { useDocsUrl } from '@/lib/docsUrl';
import type { PanelProps } from '@/threads/panels';
import { AccessGroup } from './AccessGroup';
import { LinksGroup } from './LinksGroup';
import { OverviewGroup } from './OverviewRow';
import { PeopleGroup } from './PeopleGroup';
import { StatusGroup } from './StatusGroup';
import { useThreadEnvironment } from './useThreadEnvironment';

/** The environment half of the Overview rail: status and capacity, links, access, and people. */
export function EnvironmentPanel(props: PanelProps) {
    const { thread } = props;
    const threadsDocsUrl = useDocsUrl('threads/');
    const { environment, isLoading, error, reload } = useThreadEnvironment(thread);
    const isSettingUp = environment?.repositories.some((repository) => ['pending', 'cloning', 'setup'].includes(repository.setupStatus)) ?? false;

    useEffect(() => {
        if (!isSettingUp) {
            return;
        }
        const timer = window.setInterval(reload, 3000);
        return () => window.clearInterval(timer);
    }, [isSettingUp, reload]);

    if (environment === null) {
        return (
            <div data-testid="environment-panel">
                <StatusGroup {...props} environment={null} />
                <OverviewGroup>
                    {isLoading ? (
                        <div className="flex flex-col gap-3" data-testid="environment-loading">
                            <Skeleton className="h-20 w-full" />
                            <Skeleton className="h-4 w-2/3" />
                            <Skeleton className="h-4 w-1/2" />
                        </div>
                    ) : (
                        <p className="text-[13px] text-muted" data-testid="environment-error">
                            {error ?? 'The environment could not be loaded.'}
                        </p>
                    )}
                </OverviewGroup>
            </div>
        );
    }

    return (
        <div data-testid="environment-panel">
            <StatusGroup {...props} environment={environment} />
            <LinksGroup {...props} environment={environment} onChanged={reload} />
            <AccessGroup {...props} environment={environment} />
            <PeopleGroup {...props} environment={environment} />
            <p className="px-4 py-3">
                <a href={threadsDocsUrl} target="_blank" rel="noreferrer" className="text-[12px] text-accent-text hover:underline">
                    How threads and sandboxes work
                </a>
            </p>
        </div>
    );
}
