import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { StartThreadForm } from '@/components/threads/start/StartThreadForm';
import { AppLayout } from '@/layouts/AppLayout';
import type { PageProps } from '@/types/shared';
import type { NewThreadPageData } from '@/types/threads';

export default function New({ repos, picker, defaultRepo }: PageProps<NewThreadPageData>) {
    return (
        <>
            <Head title="New thread" />
            <div className="mx-auto w-full max-w-[720px] px-4 py-12 sm:px-8" data-testid="new-thread-page">
                <h1 className="mb-6 text-[22px] font-semibold text-body">What should Yak work on?</h1>
                <StartThreadForm repos={repos} picker={picker} defaultRepo={defaultRepo} autoFocus />
            </div>
        </>
    );
}

New.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
