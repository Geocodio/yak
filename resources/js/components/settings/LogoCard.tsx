import { Button } from '@geocodio/console-ui';
import { Image as ImageIcon, Upload } from 'lucide-react';
import { useRef, useState } from 'react';

export function LogoCard({
    logoUrl,
    onSelect,
    onRemove,
}: {
    logoUrl: string | null;
    onSelect: (file: File) => void;
    onRemove: () => void;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [pendingName, setPendingName] = useState<string | null>(null);

    return (
        <div className="flex flex-wrap items-center gap-4 rounded-card border border-hair p-4">
            {logoUrl ? <img src={logoUrl} alt="Theme logo" className="h-10 w-auto" /> : <ImageIcon size={28} className="text-faint" />}
            <div className="min-w-40 flex-1">
                <div className="text-[13px]">{pendingName ?? (logoUrl ? 'Logo set' : 'No logo')}</div>
                <div className="text-[12px] text-muted">PNG or SVG, up to 512 KB. Shown top-left on the title and summary cards.</div>
            </div>
            {/*
             * A bare `<input type="file">` renders the browser's own unstyled
             * "Choose file / No file chosen" control, which cannot be themed.
             * Keep it visually hidden and drive it from a real Button so the
             * card matches the rest of the settings surfaces.
             */}
            <input
                ref={inputRef}
                type="file"
                accept="image/png,image/svg+xml"
                className="sr-only"
                aria-label="Choose a logo file"
                onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) {
                        setPendingName(file.name);
                        onSelect(file);
                    }
                }}
            />
            <Button icon={<Upload size={13} />} onClick={() => inputRef.current?.click()}>
                {logoUrl || pendingName ? 'Replace logo' : 'Choose file'}
            </Button>
            {logoUrl && (
                <Button
                    variant="tertiary"
                    onClick={() => {
                        setPendingName(null);
                        if (inputRef.current) {
                            inputRef.current.value = '';
                        }
                        onRemove();
                    }}
                >
                    Remove
                </Button>
            )}
        </div>
    );
}
