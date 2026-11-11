import assert from 'node:assert/strict';
import { test } from 'node:test';
import { addressFor, isDesktopSafari, isTransitional, stepStatuses, widthFor } from './previewState.ts';

const macSafari = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';
const macChrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
const iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const firefox = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:127.0) Gecko/20100101 Firefox/127.0';

test('desktop Safari is detected, other browsers keep the iframe', () => {
    assert.equal(isDesktopSafari(macSafari), true);
    assert.equal(isDesktopSafari(macChrome), false);
    assert.equal(isDesktopSafari(iphone), false);
    assert.equal(isDesktopSafari(firefox), false);
});

test('widths', () => {
    assert.equal(widthFor('phone'), 390);
    assert.equal(widthFor('tablet'), 768);
    assert.equal(widthFor('desktop'), '100%');
});

test('wake steps follow the state', () => {
    assert.deepEqual(stepStatuses('waking_sandbox'), ['active', 'pending', 'pending']);
    assert.deepEqual(stepStatuses('sandbox_asleep'), ['active', 'pending', 'pending']);
    assert.deepEqual(stepStatuses('starting_dev_server'), ['done', 'active', 'pending']);
    assert.deepEqual(stepStatuses('ready'), ['done', 'done', 'done']);
    assert.deepEqual(stepStatuses('failing'), ['pending', 'pending', 'pending']);
});

test('only waking states poll', () => {
    assert.equal(isTransitional('starting_dev_server'), true);
    assert.equal(isTransitional('ready'), false);
    assert.equal(isTransitional('closed'), false);
});

test('the address keeps the host and takes the typed path', () => {
    assert.equal(addressFor('https://t-abc.yak.example.com/', '/orders/1042?x=1'), 'https://t-abc.yak.example.com/orders/1042?x=1');
    assert.equal(addressFor('https://t-abc.yak.example.com/', 'orders'), 'https://t-abc.yak.example.com/orders');
    assert.equal(addressFor('https://t-abc.yak.example.com/', '//evil.test/x'), 'https://t-abc.yak.example.com/evil.test/x');
});
