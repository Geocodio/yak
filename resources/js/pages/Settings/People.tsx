import { Head, router } from '@inertiajs/react';
import { Select } from '@geocodio/console-ui';
import type { ReactNode } from 'react';
import { Card } from '@/components/accounts/AccountsSection';
import { SettingsLayout } from '@/layouts/SettingsLayout';
import { update } from '@/routes/settings/people';
import type { Person } from '@/types/people';
import type { PageProps } from '@/types/shared';

type Props = PageProps<{
    people: Person[];
}>;

const ROLE_OPTIONS = [
    { value: 'admin', label: 'Admin' },
    { value: 'member', label: 'Member' },
];

function shortDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en', { month: 'short', day: 'numeric', year: 'numeric' });
}

function PersonRow({ person }: { person: Person }) {
    const changeRole = (role: string | null) => {
        if (role === null || role === person.role) {
            return;
        }

        router.patch(update.url(person.id), { role }, { preserveScroll: true });
    };

    return (
        <li className="flex items-center gap-3 px-4 py-3 text-[13px]" data-testid="person-row">
            <span className="grid size-[30px] shrink-0 place-items-center rounded-full bg-panel-2 text-[11.5px] font-semibold text-muted" aria-hidden="true">{person.initials}</span>
            <span className="flex min-w-0 flex-1 flex-col gap-px">
                <span className="truncate font-medium">{person.name}{person.isYou && <span className="font-normal text-muted"> (you)</span>}</span>
                <span className="truncate text-[12px] text-muted">{person.email}</span>
            </span>
            <span className="hidden w-[130px] shrink-0 text-muted sm:block">{shortDate(person.joinedAt)}</span>
            <span className="w-[130px] shrink-0">
                {person.isLocked ? (
                    <span className="flex flex-col gap-0.5" data-testid="person-role-locked">
                        <span className="font-medium">{person.role === 'admin' ? 'Admin' : 'Member'}</span>
                        <span className="text-[11.5px] text-muted">{person.lockReason}</span>
                    </span>
                ) : (
                    <Select
                        options={ROLE_OPTIONS}
                        value={person.role}
                        onChange={changeRole}
                        aria-label={`Role for ${person.name}`}
                        className="w-[118px]"
                        data-testid="person-role-select"
                    />
                )}
            </span>
        </li>
    );
}

export default function People({ people }: Props) {
    return (
        <>
            <Head title="People" />

            <div className="flex flex-col gap-4">
                <p className="text-[13px] text-muted">Everyone who has signed in to this Yak. Admins manage the shared Yak accounts and decide who else is an admin. Only admins see this page.</p>

                <Card className="overflow-hidden" data-testid="people-list">
                    <div className="flex items-center gap-3 border-b border-hair px-4 py-2 text-[12px] font-medium text-muted">
                        <span className="flex-1">Person</span>
                        <span className="hidden w-[130px] sm:block">Joined</span>
                        <span className="w-[130px]">Role</span>
                    </div>
                    <ul className="divide-y divide-hair">{people.map((person) => <PersonRow key={person.id} person={person} />)}</ul>
                </Card>

                <p className="text-[12px] text-muted">A role change applies at once. The first person to sign in to a new Yak is its first admin. New people join as members when they first sign in with Google.</p>
            </div>
        </>
    );
}

People.layout = (page: ReactNode) => <SettingsLayout slug="people">{page}</SettingsLayout>;
