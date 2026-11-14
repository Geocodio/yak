import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createRankingMemory, pickedFirst } from './rankingMemory.ts';

const fakeStorage = () => {
    const data = new Map();

    return { getItem: (key) => data.get(key) ?? null, setItem: (key, value) => void data.set(key, value), data };
};

test('a recorded id gets a boost above 0 and below 1, and more picks boost more', () => {
    const memory = createRankingMemory(fakeStorage());
    assert.equal(memory.boostFor('a'), 0);
    memory.recordPick('a');
    const once = memory.boostFor('a');
    memory.recordPick('a');
    assert.ok(once > 0 && memory.boostFor('a') > once && memory.boostFor('a') < 1);
});

test('picks live under yak:palette:picks', () => {
    const storage = fakeStorage();
    createRankingMemory(storage).recordPick('a');
    assert.ok(storage.data.has('yak:palette:picks'));
});

test('storage that throws gives boost 0 and recordPick does not throw', () => {
    const broken = { getItem: () => { throw new Error('denied'); }, setItem: () => { throw new Error('denied'); } };
    const memory = createRankingMemory(broken);
    assert.doesNotThrow(() => memory.recordPick('a'));
    assert.equal(memory.boostFor('a'), 0);
    assert.equal(createRankingMemory(undefined).boostFor('a'), 0);
});

test('corrupt stored data counts as no picks', () => {
    const storage = fakeStorage();
    storage.setItem('yak:palette:picks', '{nope');
    const memory = createRankingMemory(storage);
    assert.equal(memory.boostFor('a'), 0);
    memory.recordPick('a');
    assert.ok(memory.boostFor('a') > 0);
});

test('the map never exceeds 200 ids and drops the least recently picked', () => {
    const memory = createRankingMemory(fakeStorage());
    for (let index = 0; index < 250; index++) {
        memory.recordPick(`id-${index}`);
    }
    assert.equal(memory.boostFor('id-0'), 0);
    assert.equal(memory.boostFor('id-49'), 0);
    assert.ok(memory.boostFor('id-50') > 0);
    assert.ok(memory.boostFor('id-249') > 0);
    memory.recordPick('id-50');
    memory.recordPick('new');
    assert.ok(memory.boostFor('id-50') > 0);
    assert.equal(memory.boostFor('id-51'), 0);
});

test('pickedFirst moves picked rows ahead and keeps the server order otherwise', () => {
    const rows = [{ id: 'a' }, { id: 'b' }, { id: 'c' }, { id: 'd' }];
    const boosts = { c: 0.5, d: 0.7 };
    assert.deepEqual(pickedFirst(rows, (id) => boosts[id] ?? 0).map((row) => row.id), ['d', 'c', 'a', 'b']);
    assert.deepEqual(pickedFirst(rows, () => 0).map((row) => row.id), ['a', 'b', 'c', 'd']);
});

const withStored = (raw) => {
    const storage = fakeStorage();
    storage.setItem('yak:palette:picks', raw);

    return storage;
};

test('malformed stored data never throws and gives a finite boost', () => {
    for (const raw of ['{}', '"x"', 'null', '5', '[1]', '[null]', '[["a","x"]]', '[["a",null]]']) {
        const memory = createRankingMemory(withStored(raw));
        assert.equal(memory.boostFor('a'), 0, raw);
        assert.doesNotThrow(() => memory.recordPick('a'), raw);
        assert.ok(Number.isFinite(memory.boostFor('a')) && memory.boostFor('a') > 0, raw);
    }
});

test('recordPick after malformed data writes a clean map and keeps the valid entries', () => {
    const storage = withStored('[["a",1],["b","x"],[1]]');
    const memory = createRankingMemory(storage);
    assert.ok(memory.boostFor('a') > 0);
    assert.equal(memory.boostFor('b'), 0);
    memory.recordPick('c');
    assert.deepEqual(JSON.parse(storage.data.get('yak:palette:picks')), [['a', 1], ['c', 1]]);
});

test('storage is read once and a storage that starts throwing later does not break calls', () => {
    let reads = 0;
    let broken = false;
    const storage = {
        getItem: () => { reads++; if (broken) { throw new Error('denied'); } return '[["a",2]]'; },
        setItem: () => { if (broken) { throw new Error('denied'); } },
    };
    const memory = createRankingMemory(storage);
    const first = memory.boostFor('a');
    memory.boostFor('b');
    memory.boostFor('a');
    assert.equal(reads, 1);
    broken = true;
    assert.equal(memory.boostFor('a'), first);
    assert.doesNotThrow(() => memory.recordPick('a'));
    assert.equal(memory.boostFor("a"), first);
});
