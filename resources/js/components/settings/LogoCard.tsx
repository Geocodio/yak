import { Button, TextInput } from '@geocodio/console-ui';
import { Image as ImageIcon } from 'lucide-react';

export function LogoCard({
    logoUrl,
    onSelect,
    onRemove,
}: {
    logoUrl: string | null;
    onSelect: (file: File) => void;
    onRemove: () => void;
}) {
    return (
        <div className="flex flex-wrap items-center gap-4 rounded-card border border-hair p-4">
            {logoUrl ? <img src={logoUrl} alt="Theme logo" className="h-10 w-auto" /> : <ImageIcon size={28} className="text-faint" />}
            <div className="min-w-40 flex-1">
                <div className="text-[13px]">{logoUrl ? 'Logo set' : 'No logo'}</div>
                <div className="text-[12px] text-muted">PNG or SVG, up to 512 KB. Shown top-left on the title and summary cards.</div>
            </div>
            <TextInput
                type="file"
                accept="image/png,image/svg+xml"
                className="max-w-56"
                onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) {
                        onSelect(file);
                    }
                }}
            />
            {logoUrl && (
                <Button variant="tertiary" onClick={onRemove}>
                    Remove
                </Button>
            )}
        </div>
    );
}
