import { useMemo, useState } from 'react';
import { usePage } from '@inertiajs/react';
import type { PanelProps } from '@/threads/panels';
import type { SharedProps } from '@/types/shared';
import { PreviewFrame } from './PreviewFrame';
import { PreviewStateCard } from './PreviewStateCard';
import { PreviewToolbar } from './PreviewToolbar';
import { SafariFallback } from './SafariFallback';
import { TargetSwitcher } from './TargetSwitcher';
import { addressFor, isDesktopSafari, type DeviceWidth } from './previewState';
import { usePreviewStatus } from './usePreviewStatus';
import type { ThreadPreviewState } from '@/types/threads';

export function PreviewPanel({ thread, canDrive }: PanelProps) {
    const preview = thread.preview;
    const docsUrl = usePage<SharedProps>().props.docs.baseUrl;
    const [selectedId, setSelectedId] = useState(thread.id);
    const target = preview.targets.find((candidate) => candidate.threadId === selectedId) ?? preview.targets[0];
    const initial = useMemo(() => ({ state: target.state as ThreadPreviewState, url: target.url }), [target.state, target.url]);
    const { status, restoring, requestRestore } = usePreviewStatus(target.threadId, initial);
    const [path, setPath] = useState('/');
    const [device, setDevice] = useState<DeviceWidth>('desktop');
    const [reloadKey, setReloadKey] = useState(0);

    const baseUrl = status.url ?? target.url;
    const host = baseUrl === null ? null : new URL(baseUrl).host;
    const isReady = status.state === 'ready' && status.url !== null;
    const useFallback = isReady && preview.safariFallback && isDesktopSafari(navigator.userAgent);
    const address = baseUrl === null ? null : addressFor(baseUrl, path);

    return (
        <div className="flex min-h-0 flex-1 flex-col" data-testid="preview-panel" data-state={status.state}>
            <TargetSwitcher targets={preview.targets} selectedId={target.threadId} onSelect={setSelectedId} />
            <PreviewToolbar
                host={host}
                path={path}
                onPathChange={setPath}
                onReload={() => setReloadKey((key) => key + 1)}
                device={device}
                onDeviceChange={setDevice}
                openUrl={isReady ? address : null}
                isEnabled={isReady}
            />

            {isReady && address !== null ? (
                useFallback ? (
                    <SafariFallback url={address} host={host} />
                ) : (
                    <PreviewFrame src={address} device={device} reloadKey={reloadKey} title={`Preview of ${target.title}`} />
                )
            ) : (
                <PreviewStateCard
                    state={status.state}
                    preview={preview}
                    canDrive={canDrive}
                    canRestore={target.canRestore}
                    log={status.log}
                    error={status.error}
                    isRestoring={restoring}
                    onRestore={requestRestore}
                />
            )}

            <p className="flex shrink-0 items-center justify-between gap-3 border-t border-hair px-3 py-2 text-[11.5px] text-muted">
                <span>Embedded from this thread&apos;s sandbox. Yak removes the frame headers so any app can show up here.</span>
                <a href={docsUrl} target="_blank" rel="noreferrer" className="shrink-0 text-accent-text hover:underline">
                    Preview docs
                </a>
            </p>
        </div>
    );
}
