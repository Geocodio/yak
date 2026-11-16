import assert from 'node:assert/strict';
import { test } from 'node:test';
import { resolveSetupBar } from './setupBarState.ts';

const setup = (overrides = {}) => ({ hasOwnAccount: true, brokenAccount: null, hasDevice: true, needsYakAccount: false, ...overrides });

test('nothing left means no bar, and guests get none', () => {
    assert.equal(resolveSetupBar(setup(), []), null);
    assert.equal(resolveSetupBar(null, []), null);
});

test('each row of the table', () => {
    assert.equal(resolveSetupBar(setup({ needsYakAccount: true }), []), 'yak-account');
    assert.equal(resolveSetupBar(setup({ brokenAccount: { id: 1, name: 'Personal' } }), []), 'broken-account');
    assert.equal(resolveSetupBar(setup({ hasOwnAccount: false, hasDevice: false }), []), 'both');
    assert.equal(resolveSetupBar(setup({ hasDevice: false }), []), 'teleport');
    assert.equal(resolveSetupBar(setup({ hasOwnAccount: false }), []), 'claude');
});

test('the order puts the Yak account first, then a broken account', () => {
    const everything = setup({ needsYakAccount: true, brokenAccount: { id: 1, name: 'Personal' }, hasOwnAccount: true, hasDevice: false });
    assert.equal(resolveSetupBar(everything, []), 'yak-account');
    assert.equal(resolveSetupBar({ ...everything, needsYakAccount: false }, []), 'broken-account');
});

test('dismissal hides only its own neutral state', () => {
    const bothMissing = setup({ hasOwnAccount: false, hasDevice: false });
    assert.equal(resolveSetupBar(bothMissing, ['both']), null);
    assert.equal(resolveSetupBar(bothMissing, ['teleport']), 'both');
    assert.equal(resolveSetupBar(setup({ hasDevice: false }), ['both']), 'teleport');
});

test('the warn states ignore dismissal', () => {
    assert.equal(resolveSetupBar(setup({ needsYakAccount: true }), ['yak-account', 'both', 'teleport', 'claude']), 'yak-account');
    assert.equal(resolveSetupBar(setup({ brokenAccount: { id: 1, name: 'x' } }), ['broken-account']), 'broken-account');
});
