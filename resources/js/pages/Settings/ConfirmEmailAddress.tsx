import { Head, router } from '@inertiajs/react';
import { Button } from '@geocodio/console-ui';
import { useState, type ReactNode } from 'react';
import { SettingsLayout } from '@/layouts/SettingsLayout';
import type { PageProps } from '@/types/shared';

type Props = PageProps<{
    emailAddress: { email: string; confirmed: boolean };
    confirmUrl: string;
}>;

export default function ConfirmEmailAddress({ emailAddress, confirmUrl }: Props) {
    const [isConfirming, setIsConfirming] = useState(false);

    const confirm = () => {
        router.post(confirmUrl, {}, { onStart: () => setIsConfirming(true), onFinish: () => setIsConfirming(false) });
    };

    return (
        <>
            <Head title="Confirm email address" />

            <div className="rounded-card border border-hair bg-panel p-4 shadow-card" data-testid="confirm-email-address">
                <div className="text-[13px] font-medium">Confirm {emailAddress.email} for Yak?</div>
                <div className="mt-0.5 max-w-md text-[12px] text-muted">
                    {emailAddress.confirmed
                        ? 'This address is already confirmed. Yak accepts forwarded emails from it.'
                        : 'After you confirm, Yak accepts forwarded emails from this address and starts tasks for you.'}
                </div>
                {!emailAddress.confirmed && (
                    <div className="mt-3 flex justify-end border-t border-hair pt-3">
                        <Button variant="primary" pending={isConfirming} onClick={confirm}>
                            Confirm address
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}

ConfirmEmailAddress.layout = (page: ReactNode) => <SettingsLayout slug="profile">{page}</SettingsLayout>;
