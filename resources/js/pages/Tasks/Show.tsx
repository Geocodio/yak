import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';

/**
 * Placeholder for the task detail page. `App\Http\Controllers\Tasks\TaskController::show`
 * (Task 5) renders this component name and defines the full prop contract;
 * Task 6 replaces this file with the real page built against those props.
 */
export default function Show() {
    return (
        <>
            <Head title="Task" />
            <main className="p-6 text-[13px]" data-testid="task-detail-placeholder">
                Task detail page — built in Task 6.
            </main>
        </>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
