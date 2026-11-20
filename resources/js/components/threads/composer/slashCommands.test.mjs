import assert from 'node:assert/strict';
import { test } from 'node:test';
import { filterCommands, groupCommands, menuOrder, slashQuery, startsWithCommand } from './slashCommands.ts';

const command = (name, kind = 'command') => ({ name, description: '', argumentHint: null, kind });
const all = [command('grilling', 'skill'), command('green-ci'), command('clear', 'yak'), command('regrill', 'skill')];

test('the menu opens only for a name at the start of the message', () => {
    assert.equal(slashQuery('/'), '');
    assert.equal(slashQuery('/gr'), 'gr');
    assert.equal(slashQuery('/grill topic'), null);
    assert.equal(slashQuery('hello /gr'), null);
    assert.equal(slashQuery(' /gr'), null);
    assert.equal(slashQuery(''), null);
});

test('prefix matches come before substring matches and keep their order', () => {
    assert.deepEqual(filterCommands(all, 'gr').map((c) => c.name), ['grilling', 'green-ci', 'regrill']);
    assert.deepEqual(filterCommands(all, 'ILL').map((c) => c.name), ['grilling', 'regrill']);
    assert.deepEqual(filterCommands(all, '').map((c) => c.name), ['grilling', 'green-ci', 'clear', 'regrill']);
    assert.deepEqual(filterCommands(all, 'xyz'), []);
});

test('sections follow the design order and empty sections are left out', () => {
    const sections = groupCommands(filterCommands(all, ''));
    assert.deepEqual(sections.map((s) => s.label), ['Skills', 'Commands', 'Yak']);
    assert.deepEqual(menuOrder(sections).map((c) => c.name), ['grilling', 'regrill', 'green-ci', 'clear']);
    assert.deepEqual(groupCommands(filterCommands(all, 'clear')).map((s) => s.label), ['Yak']);
});

test('a message starts with a command only when the whole name matches', () => {
    assert.equal(startsWithCommand('/grilling topic', all)?.name, 'grilling');
    assert.equal(startsWithCommand('/grilling', all)?.name, 'grilling');
    assert.equal(startsWithCommand('/grill', all), null);
    assert.equal(startsWithCommand('please /grilling', all), null);
});
