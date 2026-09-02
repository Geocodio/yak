import { Head, router, useForm } from '@inertiajs/react';
import { Button, Dialog, Field, TextInput } from '@geocodio/console-ui';
import { useState, type ReactNode } from 'react';
import { SettingsLayout } from '@/layouts/SettingsLayout';
import { destroy } from '@/routes/account';
import { update } from '@/routes/profile';
import { resend } from '@/routes/verification';
import type { PageProps } from '@/types/shared';
import type { ProfileData } from '@/types/settings';

type Props = PageProps<{ profile: ProfileData }>;

function Card({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={`rounded-card border border-hair bg-panel p-4 shadow-card ${className ?? ''}`}>{children}</div>;
}

function DeleteAccountDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const form = useForm({ password: '' });

    const submit = () => {
        form.delete(destroy.url(), {
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title="Are you sure you want to delete your account?"
            description="Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account."
            footer={
                <div className="flex justify-end gap-2">
                    <Button onClick={() => onOpenChange(false)}>Cancel</Button>
                    <Button variant="destructive" pending={form.processing} onClick={submit}>
                        Delete account
                    </Button>
                </div>
            }
        >
            <Field label="Password" error={form.errors.password}>
                <TextInput type="password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} autoFocus />
            </Field>
        </Dialog>
    );
}

export default function Profile({ profile }: Props) {
    const form = useForm({ name: profile.name, email: profile.email });
    const [confirmOpen, setConfirmOpen] = useState(false);

    const submit = () => {
        form.patch(update.url(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Profile settings" />

            <div className="flex flex-col gap-4">
                <Card className="flex flex-col gap-4">
                    <Field label="Name" error={form.errors.name}>
                        <TextInput value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus autoComplete="name" />
                    </Field>
                    <Field label="Email" error={form.errors.email}>
                        <TextInput type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} autoComplete="email" />
                    </Field>
                    {profile.hasUnverifiedEmail && (
                        <p className="text-[12px] text-muted">
                            Your email address is unverified.{' '}
                            <button
                                type="button"
                                className="cursor-pointer underline"
                                onClick={() => router.post(resend.url())}
                            >
                                Click here to re-send the verification email.
                            </button>
                        </p>
                    )}
                    <div className="flex items-center justify-end border-t border-hair pt-3">
                        <Button variant="primary" pending={form.processing} onClick={submit}>
                            Save
                        </Button>
                    </div>
                </Card>
                <Card className="flex items-center justify-between border-fail/30">
                    <div>
                        <div className="text-[13px] font-medium">Delete account</div>
                        <div className="text-[12px] text-muted">Delete your account and all of its resources</div>
                    </div>
                    <Button variant="destructive" onClick={() => setConfirmOpen(true)}>
                        Delete account
                    </Button>
                </Card>
            </div>

            <DeleteAccountDialog open={confirmOpen} onOpenChange={setConfirmOpen} />
        </>
    );
}

Profile.layout = (page: ReactNode) => <SettingsLayout slug="profile">{page}</SettingsLayout>;
