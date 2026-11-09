import { useForm } from '@inertiajs/react';
import { Badge, Button, TextInput } from '@geocodio/console-ui';
import { useRouterAction } from '@/lib/useRouterAction';
import { destroy, resend, store } from '@/routes/profile/email-addresses';
import type { EmailToYakData } from '@/types/settings';

/**
 * Where to forward email to start a task, and which addresses Yak accepts
 * it from: the sign-in address plus any extra address once confirmed.
 */
export function EmailToYakCard({ signInEmail, emailToYak }: { signInEmail: string; emailToYak: EmailToYakData }) {
    const action = useRouterAction();
    const form = useForm({ email: '' });

    const add = () => {
        form.post(store.url(), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <div className="rounded-card border border-hair bg-panel p-4 shadow-card" data-testid="email-to-yak-card">
            <div className="text-[13px] font-medium">Email to Yak</div>
            <div className="mt-0.5 max-w-xl text-[12px] text-muted">
                Forward an email to <code className="rounded-chip bg-app px-1 font-mono text-[11.5px] text-body">{emailToYak.inboundAddress}</code> to
                start a task. Add a note at the top to tell Yak what to do. Yak only accepts mail from the confirmed addresses below.
            </div>

            <ul className="mt-3 divide-y divide-hair text-[13px]">
                <li className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                    <span>{signInEmail}</span>
                    <Badge tone="neutral">Sign-in address</Badge>
                </li>
                {emailToYak.addresses.map((address) => (
                    <li key={address.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5" data-testid="email-address-row">
                        <span>{address.email}</span>
                        <div className="flex items-center gap-2">
                            {address.confirmed ? (
                                <Badge tone="ok">Confirmed</Badge>
                            ) : (
                                <>
                                    <Badge tone="warn">Waiting for confirmation</Badge>
                                    <button
                                        type="button"
                                        className="cursor-pointer text-[12px] text-muted underline disabled:cursor-default disabled:opacity-60"
                                        disabled={action.isPending(`resend-${address.id}`)}
                                        onClick={() => action.run(`resend-${address.id}`, 'post', resend.url(address.id), {}, { preserveScroll: true })}
                                    >
                                        {action.isPending(`resend-${address.id}`) ? 'Sending…' : 'Resend'}
                                    </button>
                                </>
                            )}
                            <Button
                                pending={action.isPending(`remove-${address.id}`)}
                                onClick={() => action.run(`remove-${address.id}`, 'delete', destroy.url(address.id), {}, { preserveScroll: true })}
                            >
                                Remove
                            </Button>
                        </div>
                    </li>
                ))}
            </ul>

            <form
                className="mt-1 flex flex-wrap items-start gap-2 border-t border-hair pt-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    add();
                }}
            >
                <div className="min-w-[220px] flex-1">
                    <TextInput
                        type="email"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        placeholder="Add another address you send from"
                        aria-label="Email address"
                        data-testid="add-email-address"
                        className="w-full"
                    />
                    {form.errors.email && <div className="mt-1 text-[12px] text-fail">{form.errors.email}</div>}
                </div>
                <Button variant="primary" type="submit" pending={form.processing}>
                    Add address
                </Button>
            </form>
        </div>
    );
}
