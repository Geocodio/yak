import { Dialog, Kbd } from "@geocodio/console-ui";
import { useEffect, useState } from "react";
import { isMacPlatform } from "./platform";
import {
    keycapsFor,
    PALETTE_KEYS,
    SHORTCUTS,
    type ShortcutId,
} from "./shortcuts";

const isMac = isMacPlatform();

const shortcutIds = Object.keys(SHORTCUTS) as ShortcutId[];
const groups = [...new Set(shortcutIds.map((id) => SHORTCUTS[id].group))];

function Row({
    label,
    keys,
    testId,
}: {
    label: string;
    keys: string[];
    testId?: string;
}) {
    return (
        <div
            className="flex items-center justify-between gap-4 py-1.5 text-sm"
            data-testid={testId}
        >
            <span>{label}</span>
            <Kbd keys={keys} />
        </div>
    );
}

/** Lists every entry of `SHORTCUTS`, then the palette-only keys. Opens on the `yak:open-shortcuts` window event. */
export function KeyboardShortcutsDialog() {
    const [open, setOpen] = useState(false);

    useEffect(() => {
        const onOpenRequest = () => setOpen(true);
        window.addEventListener("yak:open-shortcuts", onOpenRequest);

        return () =>
            window.removeEventListener("yak:open-shortcuts", onOpenRequest);
    }, []);

    return (
        <Dialog
            open={open}
            onOpenChange={setOpen}
            title="Keyboard shortcuts"
            width="w-[28rem]"
            data-testid="shortcuts-dialog"
        >
            {groups.map((group) => (
                <section key={group} className="mb-3">
                    <h3 className="text-xs font-medium uppercase tracking-wide text-muted">
                        {group}
                    </h3>
                    {shortcutIds
                        .filter((id) => SHORTCUTS[id].group === group)
                        .map((id) => (
                            <Row
                                key={id}
                                testId={`shortcut-${id}`}
                                label={SHORTCUTS[id].label}
                                keys={keycapsFor(id, isMac)}
                            />
                        ))}
                </section>
            ))}
            <section>
                <h3 className="text-xs font-medium uppercase tracking-wide text-muted">
                    In the palette
                </h3>
                {PALETTE_KEYS.map((entry) => (
                    <Row
                        key={entry.label}
                        testId={`palette-key-${entry.keys.join("-").toLowerCase()}`}
                        label={entry.label}
                        keys={entry.keys}
                    />
                ))}
            </section>
        </Dialog>
    );
}
