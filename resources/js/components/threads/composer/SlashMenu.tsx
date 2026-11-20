import { cn } from '@geocodio/console-ui';
import { useEffect, useRef } from 'react';
import { menuOrder, type SlashCommand, type SlashSection } from './slashCommands';

export const SLASH_MENU_ID = 'thread-slash-menu';

export const slashOptionId = (command: SlashCommand) => `thread-slash-option-${command.name}`;

/** Commands the composer offers once a message starts with `/`; the textarea keeps focus and drives it as a combobox. */
export function SlashMenu({ sections, query, activeName, onPick }: { sections: SlashSection[]; query: string; activeName: string | null; onPick: (command: SlashCommand) => void }) {
    const activeRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        activeRef.current?.scrollIntoView({ block: 'nearest' });
    }, [activeName]);

    const base = 'absolute inset-x-0 bottom-full z-20 mb-2 max-h-72 overflow-y-auto rounded-card border border-hair bg-panel p-1.5 shadow-card';

    if (menuOrder(sections).length === 0) {
        return (
            <div className={cn(base, 'px-3 py-3 text-[13px] text-muted')} data-testid="thread-slash-menu-empty" role="status">
                No command named <span className="font-mono">/{query}</span>. It will be sent to Claude as text.
            </div>
        );
    }

    return (
        <div id={SLASH_MENU_ID} role="listbox" aria-label="Commands" className={base} data-testid="thread-slash-menu">
            {sections.map((section) => (
                <div key={section.kind} role="group" aria-label={section.label}>
                    <div className="px-2.5 pt-2 pb-1 text-[11px] font-semibold tracking-wider text-muted uppercase" aria-hidden>
                        {section.label}
                    </div>
                    {section.commands.map((command) => {
                        const isActive = command.name === activeName;

                        return (
                            <div
                                key={command.name}
                                id={slashOptionId(command)}
                                ref={isActive ? activeRef : undefined}
                                role="option"
                                aria-selected={isActive}
                                // The textarea must keep focus, so the press is not allowed to move it.
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => onPick(command)}
                                className={cn('flex cursor-pointer flex-wrap items-baseline gap-x-2.5 gap-y-0.5 rounded-[7px] px-2.5 py-2 max-sm:min-h-11', isActive && 'bg-panel-2')}
                                data-testid="thread-slash-option"
                                data-name={command.name}
                            >
                                <span className="font-mono text-[13px] text-body">
                                    /{command.name}
                                    {command.argumentHint !== null && <span className="text-faint"> {command.argumentHint}</span>}
                                </span>
                                {command.description !== '' && <span className="min-w-0 text-[13px] text-muted">{command.description}</span>}
                            </div>
                        );
                    })}
                </div>
            ))}
        </div>
    );
}
