import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHarness, start } from './helpers/fake-sdk.mjs';

const questions = [{ question: 'Which colour?', header: 'Colour', options: [{ label: 'Red', description: '' }, { label: 'Blue', description: '' }], multiSelect: false }];
const finish = async (run) => { run.release(); run.send({ type: 'end' }); return run.exited; };
const boot = async () => { const run = createHarness(); run.send(start({ askUserQuestion: true })); await run.tick(); return run; };
const questionLine = (run) => run.lines().find((line) => line.type === 'runner' && line.event === 'question');

test('it registers canUseTool and allows every other tool with its input unchanged', async () => {
  const run = await boot();
  const input = { command: 'ls' };
  assert.deepEqual(await run.calls.options.canUseTool('Bash', input, {}), { behavior: 'allow', updatedInput: input });
  assert.deepEqual(await run.calls.options.canUseTool('Agent', { prompt: 'x' }, { agentID: 'a1' }), { behavior: 'allow', updatedInput: { prompt: 'x' } });
  assert.equal(questionLine(run), undefined);
  await finish(run);
});

test('it emits a question line and returns the answers keyed by question text', async () => {
  const run = await boot();
  const pending = run.calls.options.canUseTool('AskUserQuestion', { questions }, { toolUseID: 'toolu_1' });
  await run.tick();
  const line = questionLine(run);
  assert.equal(line.toolUseId, 'toolu_1');
  assert.deepEqual(line.questions, questions);
  run.send({ type: 'answer_question', id: line.id, answers: { 'Which colour?': 'Blue' } });
  assert.deepEqual(await pending, { behavior: 'allow', updatedInput: { questions, answers: { 'Which colour?': 'Blue' } } });
  assert.equal(run.lines().some((entry) => entry.event === 'question_closed' && entry.id === line.id && entry.reason === 'answered'), true);
  await finish(run);
});

test('it denies with the message on decline_question', async () => {
  const run = await boot();
  const pending = run.calls.options.canUseTool('AskUserQuestion', { questions }, {});
  await run.tick();
  run.send({ type: 'decline_question', id: questionLine(run).id, message: 'The person asked you to decide.' });
  assert.deepEqual(await pending, { behavior: 'deny', message: 'The person asked you to decide.' });
  await finish(run);
});

test('it denies a waiting question when the turn is interrupted', async () => {
  const run = await boot();
  const pending = run.calls.options.canUseTool('AskUserQuestion', { questions }, {});
  await run.tick();
  run.send({ type: 'interrupt' });
  assert.equal((await pending).behavior, 'deny');
  await finish(run);
});

test('it denies a waiting question when the tool call is aborted', async () => {
  const run = await boot();
  const controller = new AbortController();
  const pending = run.calls.options.canUseTool('AskUserQuestion', { questions }, { signal: controller.signal });
  await run.tick();
  controller.abort();
  assert.equal((await pending).behavior, 'deny');
  await finish(run);
});

test('it denies a waiting question when the runner ends and ignores answers for unknown ids', async () => {
  const run = await boot();
  const pending = run.calls.options.canUseTool('AskUserQuestion', { questions }, {});
  await run.tick();
  run.send({ type: 'answer_question', id: 'nope', answers: {} });
  run.send({ type: 'end' });
  assert.equal((await pending).behavior, 'deny');
  run.release();
  await run.exited;
});

test('it registers no canUseTool unless the start line asks for questions', async () => {
  const run = createHarness();
  run.send(start());
  await run.tick();
  assert.equal(run.calls.options.canUseTool, undefined);
  await finish(run);
});
