import { cn } from '@geocodio/console-ui';
import { widthFor, type DeviceWidth } from './previewState';

type Props = { src: string; device: DeviceWidth; reloadKey: number; title: string };

/** The preview iframe at the chosen width. Reloading bumps `reloadKey`, which remounts it. */
export function PreviewFrame({ src, device, reloadKey, title }: Props) {
    return (
        <div className={cn('flex min-h-0 flex-1 justify-center overflow-auto', device !== 'desktop' && 'bg-panel-2 p-4')} data-testid="preview-frame-wrap">
            <iframe
                key={reloadKey}
                src={src}
                title={title}
                style={{ width: widthFor(device) }}
                className={cn('h-full max-w-full border-0 bg-white', device !== 'desktop' && 'rounded-card border border-hair')}
                referrerPolicy="no-referrer-when-downgrade"
                data-testid="preview-frame"
            />
        </div>
    );
}
