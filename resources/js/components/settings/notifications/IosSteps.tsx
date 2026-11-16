import { Badge } from '@geocodio/console-ui';
import { Share, SquarePlus } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card } from '@/components/accounts/AccountsSection';

function Step({ n, hint, children }: { n: number; hint?: ReactNode; children: ReactNode }) {
    return (
        <li className="flex gap-3">
            <span className="mt-px grid size-6 shrink-0 place-items-center rounded-pill bg-brand-soft text-[12px] font-medium text-brand tnum">{n}</span>
            <div className="min-w-0 flex-1 text-[13px] leading-relaxed">
                {children}
                {hint && <span className="mt-2 flex items-center gap-2 rounded-control bg-panel-2 px-3 py-2 text-[12px] text-muted" aria-hidden="true">{hint}</span>}
            </div>
        </li>
    );
}

export function IosSteps({ isSupported }: { isSupported: boolean }) {
    if (!isSupported) {
        return <Card className="p-4 text-[13px] text-muted" data-testid="ios-unsupported">Push is not available on iOS. You keep Slack and email.</Card>;
    }

    return (
        <Card className="p-5" data-testid="ios-steps">
            <h4 id="ios-steps-heading" className="sr-only">Why iOS needs this</h4>
            <div className="mb-4 flex items-center gap-2"><Badge tone="info">iPhone and iPad</Badge><span className="text-[12px] text-muted">Use Safari. Other iOS browsers cannot add web apps.</span></div>
            <ol className="flex flex-col gap-4">
                <Step n={1} hint={<><Share size={15} />Tap the Share button in the Safari toolbar</>}>Open this page in Safari and tap <strong className="font-medium">Share</strong>.</Step>
                <Step n={2} hint={<><SquarePlus size={15} />Add to Home Screen</>}>Scroll the sheet and tap <strong className="font-medium">Add to Home Screen</strong>. Leave the name as Yak.</Step>
                <Step n={3}>Tap <strong className="font-medium">Add</strong>, then <strong className="font-medium">open Yak from the Home Screen icon</strong>. Opening it from Safari will not work.</Step>
                <Step n={4}>In the app, go to Settings, Notifications and tap <strong className="font-medium">Turn on for this device</strong>.</Step>
                <Step n={5}><strong className="font-medium">Troubleshooting.</strong> If the button does not appear, check that Notifications are allowed for Yak in iOS Settings and that you opened Yak from the Home Screen icon.</Step>
            </ol>
            <p className="mt-4 text-[12px] text-muted">Needs iOS 16.4 or later.</p>
        </Card>
    );
}
