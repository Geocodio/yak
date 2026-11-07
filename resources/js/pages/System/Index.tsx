import { Head } from '@inertiajs/react';
import { PageHeader } from '@geocodio/console-ui';
import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import type { PageProps } from '@/types/shared';
import type { SystemPageProps } from '@/types/system';

export default function Index(_props: PageProps<SystemPageProps>) {
    return (
        <>
            <Head title="System" />
            <PageHeader crumbs={['System']} />
        </>
    );
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
