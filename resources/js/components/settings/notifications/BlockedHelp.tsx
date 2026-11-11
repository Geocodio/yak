import { Button } from '@geocodio/console-ui';
import { BellOff, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { Card } from '@/components/accounts/AccountsSection';

function steps(): string {
    return 'Firefox: click the permissions icon at the left of the address bar, clear the blocked Notifications entry, then reload. Safari: open Settings for This Website, set Notifications to Allow, then reload.';
}

export function BlockedHelp({ onCheckAgain }: { onCheckAgain: () => void }) {
    const [isShowingSteps, setIsShowingSteps] = useState(false);

    return (
        <Card className="p-4" data-testid="gate-blocked">
            <div className="flex gap-3">
                <span className="grid size-9 shrink-0 place-items-center rounded-pill bg-fail-soft text-fail" aria-hidden="true"><BellOff size={17} /></span>
                <div className="min-w-0">
                    <h4 className="text-[13.5px] font-semibold">This browser is blocking notifications from Yak</h4>
                    <p className="mt-1 text-[12.5px] leading-relaxed text-muted">Yak cannot ask again once you have said no. Click the site controls icon at the left of the address bar, set Notifications to Allow, then reload this page.</p>
                    <button type="button" className="mt-1 text-[12.5px] text-accent-text hover:underline" onClick={() => setIsShowingSteps((value) => !value)} aria-expanded={isShowingSteps} data-testid="blocked-steps-link">Unblock steps for Safari and Firefox</button>
                    {isShowingSteps && <p className="mt-1 text-[12.5px] leading-relaxed text-muted" data-testid="blocked-steps">{steps()}</p>}
                    <div className="mt-3"><Button variant="secondary" icon={<RefreshCw size={13} />} onClick={onCheckAgain}>I changed it, check again</Button></div>
                </div>
            </div>
        </Card>
    );
}
