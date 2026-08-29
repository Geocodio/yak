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

test('rejects a single chapter', () => {
  const s = validScript();
  for (const shot of s.shots) shot.chapter = 'Only one';
  assert.ok(lintScriptStatic(s).some((e) => /2-5 distinct chapters/.test(e)));
});

test('rejects more than five chapters', () => {
  const s = validScript();
  s.shots = ['a', 'b', 'c', 'd', 'e', 'f'].map((c, i) => ({
    id: `s${i}`,
    chapter: `Chapter ${c}`,
    say: 'The page shows the change.',
    do: [{ scroll_to: 'body' }],
  }));
  assert.ok(lintScriptStatic(s).some((e) => /2-5 distinct chapters/.test(e)));
});

test('rejects non-contiguous chapters', () => {
  const s = validScript();
  s.shots[0].chapter = 'A';
  s.shots[1].chapter = 'B';
  s.shots[2].chapter = 'A';
  assert.ok(lintScriptStatic(s).some((e) => /contiguous/.test(e)));
});

test('rejects the reserved chapter names', () => {
  for (const reserved of ['Intro', 'Result', 'Before']) {
    const s = validScript();
    s.shots[0].chapter = reserved;
    assert.ok(
      lintScriptStatic(s).some((e) => /reserved chapter name/.test(e)),
      `${reserved} should be reserved`,
    );
  }
});

test('rejects two consecutive shots with the same focus and identical do', () => {
  const s = validScript();
  s.shots[1].do = JSON.parse(JSON.stringify(s.shots[0].do));
  s.shots[1].focus = s.shots[0].focus;
  assert.ok(lintScriptStatic(s).some((e) => /repeats the previous shot/.test(e)));
});

test('rejects localhost and preview hostnames in text fields', () => {
  const s = validScript();
  s.intro = 'Visit http://localhost:8000 to see it.';
  assert.ok(lintScriptStatic(s).some((e) => /must not mention hostnames/.test(e)));

  const t = validScript();
  t.shots[0].say = 'The page at 127.0.0.1 now lists every level.';
  assert.ok(lintScriptStatic(t).some((e) => /must not mention hostnames/.test(e)));
});

test('rejects the word Yak in text fields', () => {
  const s = validScript();
  s.outro = 'Yak opened this pull request.';
  assert.ok(lintScriptStatic(s).some((e) => /must not mention "Yak"/.test(e)));
});

test('rejects a script whose estimated cut is under 30 seconds', () => {
  const s = validScript();
  for (const shot of s.shots) shot.say = 'Short.';
  s.intro = 'Short intro.';
  s.outro = 'Done.';
  assert.ok(lintScriptStatic(s).some((e) => /estimated cut length/.test(e)));
});

test('rejects a script whose estimated cut is over 150 seconds', () => {
  const s = validScript();
  s.shots = Array.from({ length: 12 }, (_, i) => ({
    id: `s${i}`,
    chapter: i < 6 ? 'A' : 'B',
    say: new Array(32).fill('word').join(' '),
    do: [{ scroll_to: `#s${i}` }],
    focus: `#s${i}`,
  }));
  assert.ok(lintScriptStatic(s).some((e) => /estimated cut length/.test(e)));
});

test('requires between one and five screenshots', () => {
  const s = validScript();
  s.screenshots = [];
  assert.ok(lintScriptStatic(s).some((e) => /screenshots must have 1-5/.test(e)));

  const t = validScript();
  t.screenshots = new Array(6).fill(0).map((_, i) => ({ id: `s${i}`, caption: 'A caption', after_shot: 'zcta' }));
  assert.ok(lintScriptStatic(t).some((e) => /screenshots must have 1-5/.test(e)));
});

test('rejects duplicate screenshot ids', () => {
  const s = validScript();
  s.screenshots = [
    { id: 'dup', caption: 'One', after_shot: 'zcta' },
    { id: 'dup', caption: 'Two', after_shot: 'levels' },
  ];
  assert.ok(lintScriptStatic(s).some((e) => /duplicate screenshot id/.test(e)));
});

test('rejects an after_shot that names no shot', () => {
  const s = validScript();
  s.screenshots[0].after_shot = 'nope';
  assert.ok(lintScriptStatic(s).some((e) => /after_shot "nope"/.test(e)));
});

test('rejects a caption over 100 characters', () => {
  const s = validScript();
  s.screenshots[0].caption = 'x'.repeat(101);
  assert.ok(lintScriptStatic(s).some((e) => /caption/.test(e) && /100/.test(e)));
});

test('rejects localhost in a caption', () => {
  const s = validScript();
  s.screenshots[0].caption = 'The page on localhost:5173';
  assert.ok(lintScriptStatic(s).some((e) => /must not mention hostnames/.test(e)));
});

test('requires a screenshot without after_shot to carry its own do list', () => {
  const s = validScript();
  s.screenshots[0] = { id: 'standalone', caption: 'A standalone capture' };
  assert.ok(lintScriptStatic(s).some((e) => /after_shot or its own do/.test(e)));
});

test('accepts a screenshot with its own do list', () => {
  const s = validScript();
  s.screenshots[0] = {
    id: 'standalone',
    caption: 'A standalone capture',
    do: [{ navigate: '/other' }, { scroll_to: 'main' }],
  };
  assert.deepStrictEqual(lintScriptStatic(s), []);
});

test('rejects a shot with a missing chapter', () => {
  const s = validScript();
  s.shots[0].chapter = '';
  assert.ok(lintScriptStatic(s).some((e) => /chapter is required/.test(e)));
});

test('rejects a shot whose focus is present but empty', () => {
  const s = validScript();
  s.shots[0].focus = '';
  assert.ok(lintScriptStatic(s).some((e) => /focus must be a non-empty selector/.test(e)));
});

test('rejects a shots entry that is not an object', () => {
  const s = validScript();
  s.shots[0] = 'nope';
  assert.ok(lintScriptStatic(s).some((e) => /shot must be an object/.test(e)));
});

test('rejects a do entry that is not an object', () => {
  const s = validScript();
  s.shots[0].do = [42];
  assert.ok(lintScriptStatic(s).some((e) => /action must be an object/.test(e)));
});

// Finding 1: a malformed intro/outro/summary must not reach
// estimatedCutSeconds() and throw — it must be reported as a lint error like
// everything else. Regression coverage for src/v3/lint.ts:206-213.

test('a missing intro produces lint errors instead of throwing', () => {
  const s = validScript();
  delete s.intro;
  let errors: string[] = [];
  assert.doesNotThrow(() => {
    errors = lintScriptStatic(s);
  });
  assert.ok(errors.some((e) => /intro must be a non-empty string/.test(e)));
});

test('a missing outro produces lint errors instead of throwing', () => {
  const s = validScript();
  delete s.outro;
  let errors: string[] = [];
  assert.doesNotThrow(() => {
    errors = lintScriptStatic(s);
  });
  assert.ok(errors.some((e) => /outro must be a non-empty string/.test(e)));
});

test('a missing summary produces lint errors instead of throwing', () => {
  const s = validScript();
  delete s.summary;
  let errors: string[] = [];
  assert.doesNotThrow(() => {
    errors = lintScriptStatic(s);
  });
  assert.ok(errors.some((e) => /summary must have 2-5 bullets/.test(e)));
});

test('a non-array summary produces lint errors instead of throwing', () => {
  const s = validScript();
  s.summary = 'not an array';
  let errors: string[] = [];
  assert.doesNotThrow(() => {
    errors = lintScriptStatic(s);
  });
  assert.ok(errors.some((e) => /summary must have 2-5 bullets/.test(e)));
});
