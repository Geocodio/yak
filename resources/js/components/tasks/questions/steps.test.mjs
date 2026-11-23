import assert from 'node:assert/strict';
import { test } from 'node:test';
import { firstQuestionWithError, visibleStep } from './steps.ts';

test('keeps the chosen step while it exists', () => {
    assert.equal(visibleStep(1, 3), 1);
});

test('falls back to the last question when a newer round has fewer questions', () => {
    assert.equal(visibleStep(2, 1), 0);
    assert.equal(visibleStep(3, 2), 1);
});

test('finds the first question with any answer error, choices included', () => {
    const ids = ['q1', 'q2', 'q3'];

    assert.equal(firstQuestionWithError(ids, ['note']), -1);
    assert.equal(firstQuestionWithError(ids, ['answers.q2.choices.0']), 1);
    assert.equal(firstQuestionWithError(ids, ['answers.q3.other', 'answers.q2']), 1);
    assert.equal(firstQuestionWithError(ids, ['answers.q10']), -1);
});
