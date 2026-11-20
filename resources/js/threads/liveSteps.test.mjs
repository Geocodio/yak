import assert from 'node:assert/strict';
import { test } from 'node:test';
import { liveStepCount, shownSteps } from './liveSteps.ts';

const assistant = (agentId) => ({ role: 'assistant', messageId: 'm', blocks: [], ...(agentId ? { agentId } : {}) });
const user = { role: 'user', text: 'hi', kind: 'prompt' };

test('counts only Claude assistant entries after the turn start', () => {
    const entries = [assistant(), user, assistant(), assistant('a1'), assistant(), { role: 'tool_result', toolUseId: 't', isError: false, text: '' }];

    assert.equal(liveStepCount(entries, [1, 5, 6, 7, 8, 9], 5), 2);
    assert.equal(liveStepCount(entries, [1, 5, 6, 7, 8, 9], 0), 4 - 1);
});

test('shows the larger of stored and live counts', () => {
    assert.equal(shownSteps(0, 4), 4);
    assert.equal(shownSteps(6, 4), 6);
});
