import { router } from '@inertiajs/react';
import { Button } from '@geocodio/console-ui';
import { GitPullRequest } from 'lucide-react';
import { useState } from 'react';
import { PrivatePublishGate } from '@/components/threads/share/PrivatePublishGate';
import { store } from '@/routes/threads/pull-request';
import type { ThreadData } from '@/types/threads';

/**
 * Opens the pull request for the thread's branch. A private thread asks first, and the
 * acknowledgement is only sent once the person confirms the dialog. The header shows the
 * primary variant; the Changes view's phone bar shows it too.
 */
export function OpenPullRequestButton({ thread, publishWarning, variant = 'primary', className }: { thread: ThreadData; publishWarning: boolean; variant?: 'primary' | 'secondary'; className?: string }) {
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        router.post(
            store.url(thread.id),
            publishWarning ? { acknowledged_private_publish: true } : {},
            { preserveScroll: true, onStart: () => setProcessing(true), onFinish: () => setProcessing(false) },
        );
    };

    return (
        <PrivatePublishGate required={publishWarning} onConfirm={submit}>
            {(open) => (
                <Button variant={variant} icon={<GitPullRequest size={13} />} pending={processing} onClick={open} className={className} data-testid="open-pull-request">
                    Open PR
                </Button>
            )}
        </PrivatePublishGate>
    );
}
