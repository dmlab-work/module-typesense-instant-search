/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./amd');

const selection = load('instant-search/selection.js');

const row = { url: 'https://magento.loc/bag.html', hovered: false };

test('keyboard selection navigates', () => {
    assert.equal(selection.shouldNavigate(row, 'keyboard'), true);
});

test('pointer selection navigates only while the pointer is over the row', () => {
    assert.equal(selection.shouldNavigate({ ...row, hovered: true }, 'pointer'), true);
    assert.equal(selection.shouldNavigate(row, 'pointer'), false);
});

test('no selection, no url or unknown source submits the search', () => {
    assert.equal(selection.shouldNavigate(null, 'keyboard'), false);
    assert.equal(selection.shouldNavigate({ url: '', hovered: true }, 'keyboard'), false);
    assert.equal(selection.shouldNavigate({ ...row, hovered: true }, null), false);
});

test('only HOME, END, UP and DOWN are navigation keys', () => {
    [35, 36, 38, 40].forEach((key) => assert.equal(selection.isNavigationKey(key), true));
    [13, 27, 37, 39, 65].forEach((key) => assert.equal(selection.isNavigationKey(key), false));
});

test('option name falls back from title to sku to term', () => {
    assert.equal(selection.optionName({ title: 'Bag', sku: 'B1' }, 'ba'), 'Bag');
    assert.equal(selection.optionName({ sku: 'B1' }, 'ba'), 'B1');
    assert.equal(selection.optionName({}, 'ba'), 'ba');
    assert.equal(selection.optionName({ sku: 42 }, 'ba'), '42');
});
