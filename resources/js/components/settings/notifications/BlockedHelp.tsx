import { Button } from '@geocodio/console-ui';
import { BellOff, RefreshCw } from 'lucide-react';
import { Card } from '@/components/accounts/AccountsSection';

function steps(): string {
    const agent = typeof navigator === 'undefined' ? '' : navigator.userAgent;

    if (/Firefox/.test(agent)) {
        return 'Click the permissions icon at the left of the address bar, clear the blocked Notifications entry, then reload this page.';
    }

    if (/Safari/.test(agent) && !/Chrome|Chromium|Edg/.test(agent)) {
        return 'Open Safari, Settings for This Website, set Notifications to Allow, then reload this page.';
    }

    return 'Click the site controls icon at the left of the address bar, set Notifications to Allow, then reload this page.';
}

export function BlockedHelp({ onCheckAgain }: { onCheckAgain: () => void }) {
    return (
        <Card className="p-4" data-testid="gate-blocked">
            <div className="flex gap-3">
                <span className="grid size-9 shrink-0 place-items-center rounded-pill bg-fail-soft text-fail" aria-hidden="true"><BellOff size={17} /></span>
                <div className="min-w-0">
                    <h4 className="text-[13.5px] font-semibold">This browser is blocking notifications from Yak</h4>
                    <p className="mt-1 text-[12.5px] leading-relaxed text-muted">Yak cannot ask again once you have said no. {steps()}</p>
                    <div className="mt-3"><Button variant="secondary" icon={<RefreshCw size={13} />} onClick={onCheckAgain}>I changed it, check again</Button></div>
                </div>
            </div>
        </Card>
    );
}
