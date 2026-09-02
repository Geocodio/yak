import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';

export default function Placeholder({ label }: { label: string }) {
    return (
        <>
            <Head title={label} />
            <main className="p-6 text-[13px]">{label}</main>
        </>
    );
}

Placeholder.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
