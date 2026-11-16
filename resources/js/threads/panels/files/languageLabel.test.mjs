import assert from 'node:assert/strict';
import { test } from 'node:test';
import { languageLabel } from './languageLabel.ts';

test('maps known extensions', () => {
    assert.equal(languageLabel('src/a.ts'), 'TypeScript');
    assert.equal(languageLabel('x.tsx'), 'TypeScript React');
    assert.equal(languageLabel('app/Foo.php'), 'PHP');
    assert.equal(languageLabel('README.md'), 'Markdown');
});

test('falls back to Plain text', () => {
    assert.equal(languageLabel('Makefile'), 'Plain text');
    assert.equal(languageLabel('a.zzz'), 'Plain text');
});
