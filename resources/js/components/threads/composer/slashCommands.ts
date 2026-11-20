export type SlashCommand = {
    name: string;
    description: string;
    argumentHint: string | null;
    kind: 'yak' | 'skill' | 'command';
};

export type SlashCommandsData = {
    commands: SlashCommand[];
    /** When Claude reported the list, or null while only Yak's own commands are known. */
    capturedAt: string | null;
};

export type SlashSection = { kind: SlashCommand['kind']; label: string; commands: SlashCommand[] };

const SECTIONS: { kind: SlashCommand['kind']; label: string }[] = [
    { kind: 'skill', label: 'Skills' },
    { kind: 'command', label: 'Commands' },
    { kind: 'yak', label: 'Yak' },
];

/** The command name being typed: the text after a leading `/` up to the first space, or null once the message is not just a name. */
export function slashQuery(message: string): string | null {
    const match = /^\/([^\s]*)$/.exec(message);

    return match === null ? null : match[1];
}

/** Commands whose name starts with the query come first, then those that contain it; each group keeps its order. */
export function filterCommands(commands: SlashCommand[], query: string): SlashCommand[] {
    const needle = query.toLowerCase();
    const prefix = commands.filter((command) => command.name.toLowerCase().startsWith(needle));
    const substring = commands.filter((command) => !command.name.toLowerCase().startsWith(needle) && command.name.toLowerCase().includes(needle));

    return [...prefix, ...substring];
}

/** Matches in the order the menu draws them: sections as in the design, best matches first inside each. */
export function groupCommands(matches: SlashCommand[]): SlashSection[] {
    return SECTIONS.map((section) => ({ ...section, commands: matches.filter((command) => command.kind === section.kind) })).filter((section) => section.commands.length > 0);
}

/** The flat list the arrow keys walk, in drawing order. */
export function menuOrder(sections: SlashSection[]): SlashCommand[] {
    return sections.flatMap((section) => section.commands);
}

/** The command a message starts with, when it is one the thread lists. */
export function startsWithCommand(message: string, commands: SlashCommand[]): SlashCommand | null {
    const match = /^\/([^\s/]+)(\s|$)/.exec(message.trimStart());

    return match === null ? null : (commands.find((command) => command.name === match[1]) ?? null);
}
