import { useState } from 'react';
import { PrivatePublishGate } from '@/components/threads/share/PrivatePublishGate';

/** Test-only host for PrivatePublishGate until the Open PR button wraps it. */
export default function PublishGateHarness({ required }: { required: boolean }) {
    const [confirmedCount, setConfirmedCount] = useState(0);

    return (
        <PrivatePublishGate required={required} onConfirm={() => setConfirmedCount((count) => count + 1)}>
            {(open) => (
                <div className="p-6">
                    <button type="button" onClick={open} data-testid="stub-open-pr">Open PR</button>
                    <span data-testid="confirmed-count">{confirmedCount}</span>
                </div>
            )}
        </PrivatePublishGate>
    );
}
