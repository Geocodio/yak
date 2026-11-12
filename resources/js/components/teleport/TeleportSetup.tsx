import { usePage, usePoll } from '@inertiajs/react';
import { Spinner } from '@geocodio/console-ui';
import { Check } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { CopyBox } from '@/components/settings/devices/CopyBox';
import { useDocsUrl } from '@/lib/docsUrl';
import type { SharedProps } from '@/types/shared';

const FOCUS = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

function Step({ n, children }: { n: number; children: React.ReactNode }) {
    return (
        <li className="flex gap-3">
            <span className="mt-px grid size-[22px] shrink-0 place-items-center rounded-pill bg-panel-2 text-[12px] font-semibold text-muted tnum">{n}</span>
            <div className="flex min-w-0 flex-1 flex-col gap-2">{children}</div>
        </li>
    );
}

/**
 * Install steps for Teleport with a live pairing row. It reloads `setup` every
 * three seconds until a computer has paired, plus any props named in `alsoReload`.
 * When the person already had a computer on mount, the pairing row stays hidden
 * (they are adding another one).
 */
export function TeleportSetup({ alsoReload = [] }: { alsoReload?: string[] }) {
    const setup = usePage<SharedProps>().props.setup;
    const hasDevice = setup?.hasDevice ?? false;
    const startedWithDevice = useRef(hasDevice);
    const manualDocsUrl = useDocsUrl('teleport/#install');
    const { start, stop } = usePoll(3000, { only: ['setup', ...alsoReload] }, { autoStart: false, keepAlive: true });

    useEffect(() => {
        if (hasDevice) {
            stop();

            return;
        }

        start();

        return stop;
    }, [hasDevice]);

    if (setup === null) {
        return null;
    }

    return (
        <div className="flex flex-col gap-4 text-[13px]" data-testid="teleport-setup">
            <ol className="flex flex-col gap-4">
                <Step n={1}>
                    <p className="leading-relaxed">Run this in a terminal on your computer.</p>
                    <CopyBox command={setup.installCommand} label="install command" testId="teleport-install-command" />
                    <p className="text-[12px] leading-relaxed text-muted">
                        It installs the Yak plugin for Claude Code, puts <code className="font-mono text-[11.5px]">yak</code> on your PATH and starts the sign-in for this Yak.
                    </p>
                    <details className="text-[12px] text-muted" data-testid="teleport-manual">
                        <summary className={`w-fit cursor-pointer rounded-[3px] text-accent-text hover:underline ${FOCUS}`}>See what it does, or install by hand</summary>
                        <div className="mt-2 flex flex-col gap-1.5">
                            <p>Run these in order, in any terminal where <code className="font-mono text-[11.5px]">claude</code> works.</p>
                            {[...setup.manualInstallCommands, setup.loginCommand].map((command) => <CopyBox key={command} command={command} label="command" />)}
                            <a href={manualDocsUrl} target="_blank" rel="noreferrer" className={`w-fit rounded-[3px] text-accent-text hover:underline ${FOCUS}`}>Install steps in the docs</a>
                        </div>
                    </details>
                </Step>
                <Step n={2}>
                    <p className="leading-relaxed">A browser tab opens with a code. Check it against your terminal and approve.</p>
                </Step>
            </ol>

            {!startedWithDevice.current && (
                hasDevice ? (
                    <div role="status" className="flex flex-wrap items-center gap-x-2.5 gap-y-1 rounded-card bg-ok-soft px-3.5 py-3" data-testid="teleport-connected">
                        <Check size={15} className="text-ok" aria-hidden="true" />
                        <span className="font-medium">{setup.latestDeviceName ?? 'Your computer'} connected</span>
                        <span className="text-muted">Try it: open a thread and choose Teleport to computer.</span>
                    </div>
                ) : (
                    <div role="status" className="flex flex-wrap items-center gap-x-2.5 gap-y-1 rounded-card bg-panel-2 px-3.5 py-3" data-testid="teleport-waiting">
                        <Spinner size="sm" />
                        <span className="font-medium">Waiting for your computer</span>
                        <span className="text-muted">This updates by itself. You can close it and come back.</span>
                    </div>
                )
            )}
        </div>
    );
}
