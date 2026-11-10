import { toast } from '@geocodio/console-ui';
import { Copy } from 'lucide-react';

/** An email address in code style with a one-click copy button beside it. */
export function CopyableAddress({ address, label = 'Copy' }: { address: string; label?: string | null }) {
    const copy = () => {
        navigator.clipboard.writeText(address).then(
            () => toast.success(`Copied ${address}`),
            () => toast.error('Could not copy the address.'),
        );
    };

    return (
        <span className="inline-flex items-center gap-1.5 align-middle">
            <code className="rounded-chip border border-hair bg-app px-1.5 py-px font-mono text-[11.5px] text-body">{address}</code>
            <button
                type="button"
                onClick={copy}
                className="inline-flex cursor-pointer items-center gap-1 text-[12px] text-accent-text hover:underline"
                aria-label={`Copy ${address}`}
                data-testid="copy-address"
            >
                <Copy size={12} />
                {label}
            </button>
        </span>
    );
}
