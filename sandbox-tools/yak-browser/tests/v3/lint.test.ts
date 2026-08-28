import { test } from 'node:test';
import assert from 'node:assert';
import { lintScriptStatic } from '../../src/v3/lint.ts';

/** A minimal script that passes every static rule. */
export function validScript(): any {
  return {
    version: 3,
    title: 'ZIP-level demographics and national benchmarks in the Census guide',
    intro: 'The Census demographics guide now documents every ACS geography level and shows how to benchmark against national averages.',
    summary: ['Eleven geography levels documented', 'ZIP vs ZCTA explained', 'Worked benchmark example'],
    outro: 'Four new geography levels and a worked benchmark. Ready for review.',
    shots: [
      {
        id: 'levels',
        chapter: 'Geography levels',
        say: 'The guide now lists all eleven geography levels, with Region and Nation among the new ones.',
        do: [{ navigate: '/guides/demographics-census/' }, { scroll_to: 'ul.levels' }],
        focus: 'ul.levels',
      },
      {
        id: 'zcta',
        chapter: 'Geography levels',
        say: 'ZIP codes resolve to ZCTAs, and the guide is explicit about the addresses that have none.',
        do: [{ scroll_to: 'section#zcta' }],
        focus: 'section#zcta',
      },
      {
        id: 'benchmark',
        chapter: 'Benchmarks',
        say: 'The worked example compares a county against the national average side by side.',
        do: [{ click: 'a#benchmark' }],
        focus: 'table.benchmark',
      },
    ],
    screenshots: [{ id: 'zcta-section', caption: 'The new ZCTA section with its warning', after_shot: 'zcta' }],
  };
}

test('a valid script produces no errors', () => {
  assert.deepStrictEqual(lintScriptStatic(validScript()), []);
});

test('rejects a non-object', () => {
  assert.ok(lintScriptStatic('nope').some((e) => /must be a JSON object/.test(e)));
});

test('rejects the wrong version', () => {
  const s = validScript();
  s.version = 2;
  assert.ok(lintScriptStatic(s).some((e) => /version must be 3/.test(e)));
});

test('rejects a title over 90 characters', () => {
  const s = validScript();
  s.title = 'x'.repeat(91);
  assert.ok(lintScriptStatic(s).some((e) => /title/.test(e) && /90/.test(e)));
});

test('rejects an intro over 240 characters', () => {
  const s = validScript();
  s.intro = 'x'.repeat(241);
  assert.ok(lintScriptStatic(s).some((e) => /intro/.test(e) && /240/.test(e)));
});

test('rejects an outro over 160 characters', () => {
  const s = validScript();
  s.outro = 'x'.repeat(161);
  assert.ok(lintScriptStatic(s).some((e) => /outro/.test(e) && /160/.test(e)));
});

test('rejects fewer than two summary bullets', () => {
  const s = validScript();
  s.summary = ['only one'];
  assert.ok(lintScriptStatic(s).some((e) => /summary must have 2-5/.test(e)));
});

test('rejects a summary bullet over 60 characters', () => {
  const s = validScript();
  s.summary = ['x'.repeat(61), 'fine'];
  assert.ok(lintScriptStatic(s).some((e) => /summary\[0\]/.test(e) && /60/.test(e)));
});

test('rejects fewer than three shots', () => {
  const s = validScript();
  s.shots = s.shots.slice(0, 2);
  assert.ok(lintScriptStatic(s).some((e) => /shots must have 3-12/.test(e)));
});

test('rejects duplicate shot ids', () => {
  const s = validScript();
  s.shots[1].id = s.shots[0].id;
  assert.ok(lintScriptStatic(s).some((e) => /duplicate shot id/.test(e)));
});

test('rejects a shot id that is not a slug', () => {
  const s = validScript();
  s.shots[0].id = 'Not A Slug';
  assert.ok(lintScriptStatic(s).some((e) => /slug/.test(e)));
});

test('rejects a say over 180 characters', () => {
  const s = validScript();
  s.shots[0].say = 'word '.repeat(20) + 'x'.repeat(90);
  assert.ok(lintScriptStatic(s).some((e) => /say/.test(e) && /180/.test(e)));
});

test('rejects a say over 32 words', () => {
  const s = validScript();
  s.shots[0].say = new Array(33).fill('word').join(' ');
  assert.ok(lintScriptStatic(s).some((e) => /32 words/.test(e)));
});

test('rejects an empty do list', () => {
  const s = validScript();
  s.shots[0].do = [];
  assert.ok(lintScriptStatic(s).some((e) => /1-6 actions/.test(e)));
});

test('rejects more than six actions', () => {
  const s = validScript();
  s.shots[0].do = new Array(7).fill({ scroll_to: 'body' });
  assert.ok(lintScriptStatic(s).some((e) => /1-6 actions/.test(e)));
});

test('rejects a shot made only of waits', () => {
  const s = validScript();
  s.shots[0].do = [{ wait: 500 }];
  assert.ok(lintScriptStatic(s).some((e) => /at least one physical action/.test(e)));
});

test('rejects an unknown action key', () => {
  const s = validScript();
  s.shots[0].do = [{ teleport: '/x' }];
  assert.ok(lintScriptStatic(s).some((e) => /unknown action/.test(e)));
});

test('rejects a fill without a value', () => {
  const s = validScript();
  s.shots[0].do = [{ fill: 'input#q' }];
  assert.ok(lintScriptStatic(s).some((e) => /fill.*value/.test(e)));
});

test('rejects a numeric wait over 5000 ms', () => {
  const s = validScript();
  s.shots[0].do = [{ navigate: '/' }, { wait: 5001 }];
  assert.ok(lintScriptStatic(s).some((e) => /wait.*5000/.test(e)));
});
