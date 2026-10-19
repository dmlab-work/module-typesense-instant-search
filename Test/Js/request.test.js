/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./amd');

const request = load('instant-search/request.js');

const direct = {
    mode: 'direct',
    host: 'typesense.loc',
    apiKey: 'k/+=',
    collection: 'alias_1_public',
    queryBy: 'name,sku',
    queryByWeights: '5,3',
    resultLimit: 7,
    endpoint: 'https://magento.loc/dmlab_typesense/ajax/search/'
};

test('direct URL carries the key as a query param and no headers', () => {
    const built = request.build('red shoe', direct, 'direct');
    const url = new URL(built.url);

    assert.deepEqual(Object.keys(built), ['url']);
    assert.equal(url.origin + url.pathname, 'https://typesense.loc/collections/alias_1_public/documents/search');
    assert.equal(url.searchParams.get('q'), 'red shoe');
    assert.equal(url.searchParams.get('query_by'), 'name,sku');
    assert.equal(url.searchParams.get('query_by_weights'), '5,3');
    assert.equal(url.searchParams.get('per_page'), '7');
    assert.equal(url.searchParams.get('x-typesense-api-key'), 'k/+=');
});

test('direct URL omits empty weights and defaults the page size', () => {
    const url = new URL(request.build('a', { ...direct, queryByWeights: '', resultLimit: 0 }, 'direct').url);

    assert.equal(url.searchParams.has('query_by_weights'), false);
    assert.equal(url.searchParams.get('per_page'), '10');
});

test('proxy URL appends q with ? or &', () => {
    assert.equal(request.build('a b', direct, 'proxy').url, 'https://magento.loc/dmlab_typesense/ajax/search/?q=a%20b');
    assert.equal(
        request.build('x', { endpoint: 'https://magento.loc/s?___store=de' }, 'proxy').url,
        'https://magento.loc/s?___store=de&q=x'
    );
});

test('initial state is direct only with a usable direct config', () => {
    assert.deepEqual(request.initialState(direct), { mode: 'direct', pausedUntil: 0 });
    assert.equal(request.initialState({ ...direct, apiKey: '' }).mode, 'proxy');
    assert.equal(request.initialState({ ...direct, mode: 'proxy' }).mode, 'proxy');
});

test('a direct failure switches to the proxy for the rest of the page', () => {
    const state = request.nextMode({ mode: 'direct', pausedUntil: 0 }, { failed: true, now: 1000 });

    assert.deepEqual(state, { mode: 'proxy', pausedUntil: 0 });
    assert.deepEqual(request.nextMode(state, { now: 2000 }), state);
});

test('a direct success keeps direct mode', () => {
    const state = { mode: 'direct', pausedUntil: 0 };

    assert.equal(request.nextMode(state, { now: 1000 }), state);
});

test('proxy unavailable pauses for 30 s, then expires', () => {
    const state = request.nextMode({ mode: 'proxy', pausedUntil: 0 }, { unavailable: true, now: 1000 });

    assert.equal(request.PAUSE_MS, 30000);
    assert.deepEqual(state, { mode: 'proxy', pausedUntil: 31000 });
    assert.equal(request.isPaused(state, 30999), true);
    assert.equal(request.isPaused(state, 31000), false);
});

test('a plain proxy failure does not pause', () => {
    const state = { mode: 'proxy', pausedUntil: 0 };

    assert.equal(request.nextMode(state, { failed: true, now: 1000 }), state);
    assert.equal(request.isPaused(state, 1000), false);
});
