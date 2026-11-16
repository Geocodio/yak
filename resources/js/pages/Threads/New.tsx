import { Head, router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { StartScreen } from '@/components/start/StartScreen';
import { StartThreadPanel } from '@/components/threads/start/StartThreadPanel';
import { home } from '@/routes';
import { AppLayout } from '@/layouts/AppLayout';
import type { PageProps } from '@/types/shared';
import type { NewThreadPageData } from '@/types/threads';

export default function New({ repos, picker, defaultRepo }: PageProps<NewThreadPageData>) {
    const dismiss = () => (window.history.length > 1 ? window.history.back() : router.visit(home.url()));
    const canStart = repos.length > 0 && picker.accounts.some((account) => account.usable !== false);

    return (
        <>
            <Head title="New thread" />
            {canStart ? (
                <StartScreen testId="new-thread-page" repos={repos} picker={picker} defaultRepo={defaultRepo} reloadOnly={['repos', 'picker', 'defaultRepo']} autoFocus onDismiss={dismiss} />
            ) : (
                <div className="mx-auto w-full max-w-[660px] px-4 py-12 sm:px-8" data-testid="new-thread-page">
                    <StartThreadPanel repos={repos} picker={picker} defaultRepo={defaultRepo} reloadOnly={['repos', 'picker', 'defaultRepo']} autoFocus onDismiss={dismiss} />
                </div>
            )}
        </>
    );
}

New.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
