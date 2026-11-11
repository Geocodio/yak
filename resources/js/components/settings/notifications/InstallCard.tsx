import { Link } from '@inertiajs/react';
import { Button, EmptyState } from '@geocodio/console-ui';
import { Bell, Check, Download } from 'lucide-react';
import { Card } from '@/components/accounts/AccountsSection';
import { useInstallPrompt } from '@/pwa/useInstallPrompt';

export function InstallCard({ isStandalone, isIos, onShowIosSteps }: { isStandalone: boolean; isIos: boolean; onShowIosSteps: () => void }) {
    const { canInstall, install } = useInstallPrompt();

    if (isStandalone) {
        return (
            <Card className="p-4" data-testid="install-done">
                <EmptyState
                    icon={<Check size={18} />}
                    title="Yak is installed on this device"
                    body="You opened Yak from its app icon, so you get notifications and the page opens without browser chrome."
                    action={<Link href="#push-heading"><Button variant="secondary" icon={<Bell size={13} />}>Go to push settings</Button></Link>}
                />
            </Card>
        );
    }

    return (
        <Card className="p-5" data-testid="desktop-install">
            <div className="flex flex-wrap items-center gap-4">
                <span className="grid size-12 shrink-0 place-items-center rounded-card bg-brand text-[18px] font-semibold text-[var(--color-brand-fg,white)]" aria-hidden="true">Y</span>
                <div className="min-w-0 flex-1">
                    <div className="text-[13.5px] font-semibold">Yak, a web app</div>
                    <div className="text-[12px] text-muted">No download. It updates itself like this page does.</div>
                </div>
                {canInstall && <Button variant="primary" icon={<Download size={14} />} onClick={() => void install()} data-testid="install-button">Install Yak</Button>}
                {!canInstall && isIos && <Button variant="primary" onClick={onShowIosSteps}>Show iPhone steps</Button>}
            </div>
            {!canInstall && (
                <p className="mt-4 border-t border-hair pt-3 text-[12px] leading-relaxed text-muted">
                    Not seeing the button? Firefox and Safari on a Mac do not support installing. Chrome and Edge put an install icon in the address bar. On Android, use the browser menu and tap Install app. On iPhone,{' '}
                    <button type="button" className="text-accent-text hover:underline" onClick={onShowIosSteps}>follow the iPhone steps</button>.
                </p>
            )}
        </Card>
    );
}
