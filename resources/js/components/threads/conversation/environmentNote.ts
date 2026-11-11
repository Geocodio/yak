export type EnvironmentNoteRow = { heading: string; items: string[] };

const headingPattern = /^(?:##\s+(.+?)|\*\*(.+?)\*\*:?)\s*$/;

/** Splits the note on `## ` or `**Heading**` lines; headings it does not know are kept as their own row. */
export function parseEnvironmentNote(markdown: string): { rows: EnvironmentNoteRow[]; raw: string } {
    const rows: EnvironmentNoteRow[] = [];
    let current: EnvironmentNoteRow | null = null;

    markdown.split('\n').forEach((line) => {
        const trimmed = line.trim();

        if (trimmed === '') {
            return;
        }

        const match = headingPattern.exec(trimmed);

        if (match) {
            current = { heading: match[1] ?? match[2], items: [] };
            rows.push(current);

            return;
        }

        if (current === null) {
            current = { heading: 'Notes', items: [] };
            rows.push(current);
        }

        current.items.push(trimmed.replace(/^[-*]\s+/, ''));
    });

    return { rows, raw: markdown };
}
