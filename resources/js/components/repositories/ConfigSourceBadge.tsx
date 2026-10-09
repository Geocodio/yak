import { Badge } from '@geocodio/console-ui';
import { Lock, Pencil } from 'lucide-react';
import type { ReactNode } from 'react';
import type { YakConfig, YakConfigFile } from '@/types/repositories';

export function configFile(config: YakConfig | undefined, name: string): YakConfigFile | undefined {
    return config?.files.find((file) => file.name === name);
}

/**
 * Marks a field whose value comes from a `.yak/` file and links to that file.
 * A file whose newest commit is invalid is shown in the warn tone with the
 * commit the value was read from.
 */
export function ConfigSourceBadge({ field, config, fileName }: { field: string; config: YakConfig; fileName: string }) {
    const file = configFile(config, fileName);
    const isStale = file !== undefined && !file.valid && file.validCommitSha !== null;
    const label = isStale ? `${fileName} at ${file.validCommitSha?.slice(0, 7)}` : fileName;

    return (
        <a href={file?.blobUrl ?? undefined} target="_blank" rel="noopener noreferrer" data-testid={`config-source-${field}`}
            aria-label={`Value from .yak/${fileName}, opens on GitHub in a new tab`}
            className="inline-flex"
        >
            <Badge tone={isStale ? 'warn' : 'accent'} className="inline-flex items-center gap-1">
                <Lock size={10} />
                {label}
            </Badge>
        </a>
    );
}

export function ProposeChangeLink({ config, fileName }: { config: YakConfig; fileName: string }) {
    const href = configFile(config, fileName)?.editUrl;

    if (!href) {
        return null;
    }

    return (
        <a href={href} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-[12px] text-accent-text hover:underline">
            <Pencil size={12} /> Propose a change
        </a>
    );
}

/** The file value of a locked field. It is not bound to the form, so saving never writes it to the database. */
export function LockedValue({ children, mono = false, testId }: { children: ReactNode; mono?: boolean; testId?: string }) {
    return (
        <div
            data-testid={testId}
            className={`min-h-[34px] whitespace-pre-wrap break-words rounded-control border border-hair bg-panel-2 px-3 py-2 text-[13px] text-muted ${mono ? 'font-mono text-[12px]' : ''}`}
        >
            {children}
        </div>
    );
}

/** A label row with the source badge, for use above a `LockedValue`. */
export function LockedField({
    label,
    field,
    config,
    fileName,
    children,
    mono,
    propose = false,
    hideBadge = false,
}: {
    label: string;
    field: string;
    config: YakConfig;
    fileName: string;
    children: ReactNode;
    mono?: boolean;
    propose?: boolean;
    hideBadge?: boolean;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <div className="flex flex-wrap items-center gap-2 text-[12px] font-medium">
                {label}
                {!hideBadge && <ConfigSourceBadge field={field} config={config} fileName={fileName} />}
            </div>
            <LockedValue mono={mono} testId={`locked-${field}`}>
                {children}
            </LockedValue>
            {propose && <ProposeChangeLink config={config} fileName={fileName} />}
        </div>
    );
}
