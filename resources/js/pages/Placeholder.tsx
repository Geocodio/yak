import { Head } from '@inertiajs/react';

export default function Placeholder({ label }: { label: string }) {
    return (
        <>
            <Head title={label} />
            <main className="p-6 text-[13px]">{label}</main>
        </>
    );
}
