import { Button, ConfirmDialog, Field, TextInput } from '@geocodio/console-ui';
import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { destroy, store } from '@/routes/marketplaces';
import type { MarketplaceRow } from '@/types/skills';

export function MarketplaceList({ marketplaces: rows }: { marketplaces: MarketplaceRow[] }) {
    const form = useForm({ source: '' });
    const [pendingRemoval, setPendingRemoval] = useState<string | null>(null);
    const [removing, setRemoving] = useState(false);

    const addMarketplace = () => {
        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const confirmRemoval = () => {
        if (!pendingRemoval) {
            return;
        }

        setRemoving(true);
        router.delete(destroy.url(pendingRemoval), {
            preserveScroll: true,
            onFinish: () => {
                setRemoving(false);
                setPendingRemoval(null);
            },
        });
    };

    return (
        <section className="rounded-card border border-hair bg-panel p-4 shadow-card">
            {rows.length === 0 ? (
                <p className="text-[12.5px] text-muted">No marketplaces configured.</p>
            ) : (
                <ul className="divide-y divide-hair">
                    {rows.map((marketplace) => (
                        <li key={marketplace.name} className="flex items-center justify-between py-3" data-testid={`marketplace-${marketplace.name}`}>
                            <div>
                                <div className="text-[13px] font-semibold">{marketplace.name}</div>
                                <div className="mt-0.5 text-[12px] text-muted">
                                    {marketplace.source || '—'}
                                    {marketplace.lastUpdatedAgo ? ` · Updated ${marketplace.lastUpdatedAgo}` : ''}
                                </div>
                            </div>
                            <Button variant="tertiary" onClick={() => setPendingRemoval(marketplace.name)} data-testid={`remove-marketplace-${marketplace.name}`}>
                                Remove
                            </Button>
                        </li>
                    ))}
                </ul>
            )}

            <div className="mt-4 flex items-end gap-2">
                <Field label="Add marketplace" className="flex-1" error={form.errors.source}>
                    <TextInput
                        placeholder="github:owner/repo or git URL"
                        value={form.data.source}
                        onChange={(e) => form.setData('source', e.target.value)}
                        data-testid="add-marketplace-input"
                    />
                </Field>
                <Button pending={form.processing} pendingLabel="Adding…" onClick={addMarketplace} data-testid="add-marketplace-submit">
                    Add
                </Button>
            </div>

            <ConfirmDialog
                open={pendingRemoval !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingRemoval(null);
                    }
                }}
                title="Remove marketplace?"
                body={`This removes ${pendingRemoval} from the configured marketplaces.`}
                confirmLabel="Remove"
                busy={removing}
                confirmTestId="remove-marketplace-confirm"
                onConfirm={confirmRemoval}
            />
        </section>
    );
}
