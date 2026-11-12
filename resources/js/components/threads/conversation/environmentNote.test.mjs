import assert from 'node:assert/strict';
import { test } from 'node:test';
import { parseEnvironmentNote } from './environmentNote.ts';

test('known headings split into rows with bullets stripped', () => {
    const { rows, raw } = parseEnvironmentNote('## System\n- macOS arm64 to Linux\n\n## Paths\n- /Users/me/app to /workspace/app\n- ~/.cache');

    assert.deepEqual(rows, [
        { heading: 'System', items: ['macOS arm64 to Linux'] },
        { heading: 'Paths', items: ['/Users/me/app to /workspace/app', '~/.cache'] },
    ]);
    assert.ok(raw.startsWith('## System'));
});

test('bold headings work and unknown headings are kept', () => {
    const { rows } = parseEnvironmentNote('**Refresh commands**\nRan composer install\n**Something new**\nodd item');

    assert.deepEqual(rows, [
        { heading: 'Refresh commands', items: ['Ran composer install'] },
        { heading: 'Something new', items: ['odd item'] },
    ]);
});

test('lines before any heading land under Notes', () => {
    assert.deepEqual(parseEnvironmentNote('Moved from macOS to a Linux sandbox.\nRan npm ci').rows, [
        { heading: 'Notes', items: ['Moved from macOS to a Linux sandbox.', 'Ran npm ci'] },
    ]);
});

test('an empty string has no rows', () => {
    assert.deepEqual(parseEnvironmentNote('  \n'), { rows: [], raw: '  \n' });
});
