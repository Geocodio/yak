import type { FileEntryData } from '@/types/thread-files';
import type { PaletteAction } from './types.ts';

const MAXIMUM_FILE_ROWS = 50;
const MAXIMUM_DIRECTORIES = 60;
const SKIPPED_DIRECTORIES = new Set(['.git', 'node_modules', 'vendor']);

export const FILES_UNAVAILABLE = 'Files are not available right now';

type DirectoryResponse = { status: number; body: { entries?: FileEntryData[] } };

/**
 * A step that lists the repository's files matching the query. Directories load breadth first, one level at a time,
 * and each is fetched once for the life of the step. When the top level cannot be read, the step shows one
 * unavailable row in place of an empty list.
 */
export function openFileStep(fetchDirectory: (path: string) => Promise<DirectoryResponse>, openFile: (path: string) => void): NonNullable<PaletteAction['steps']> {
    const directories = new Map<string, Promise<FileEntryData[] | null>>();
    const load = (path: string): Promise<FileEntryData[] | null> => {
        if (!directories.has(path)) {
            directories.set(
                path,
                fetchDirectory(path)
                    .then(({ status, body }) => (status === 200 ? (body.entries ?? []) : null))
                    .catch(() => null),
            );
        }

        return directories.get(path) as Promise<FileEntryData[] | null>;
    };

    return {
        title: 'Open file',
        placeholder: 'Search files…',
        items: async (query) => {
            if ((await load('')) === null) {
                return [{ id: 'thread-files-unavailable', label: 'No files to show', keywords: [], unavailable: FILES_UNAVAILABLE }];
            }

            const words = query.toLowerCase().split(/\s+/).filter(Boolean);
            const matches: PaletteAction[] = [];
            let level = [''];
            let visited = 0;

            while (level.length > 0 && matches.length < MAXIMUM_FILE_ROWS && visited < MAXIMUM_DIRECTORIES) {
                const batch = level.slice(0, MAXIMUM_DIRECTORIES - visited);
                visited += batch.length;
                const listings = await Promise.all(batch.map(load));
                level = [];

                for (const entry of listings.flatMap((listing) => listing ?? [])) {
                    if (entry.isDirectory) {
                        if (!SKIPPED_DIRECTORIES.has(entry.name)) {
                            level.push(entry.path);
                        }
                    } else if (matches.length < MAXIMUM_FILE_ROWS && words.every((word) => entry.path.toLowerCase().includes(word))) {
                        matches.push({ id: `thread-file-${entry.path}`, label: entry.path, keywords: [], run: () => openFile(entry.path) });
                    }
                }
            }

            return matches;
        },
    };
}
