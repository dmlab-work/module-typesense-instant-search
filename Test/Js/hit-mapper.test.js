/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { load } = require('./amd');

const mapper = load('instant-search/hit-mapper.js');

const cfg = {
    priceField: 'price',
    taxFactors: { 0: 1, 2: 1.0825 },
    currencyRate: 1,
    baseUrl: 'https://magento.loc/',
    productUrlSuffix: '.html',
    mediaUrl: 'https://magento.loc/media/catalog/product/',
    displayFields: { title: true, sku: true, price: true, image: true }
};

test('price applies the tax factor of the class, rounded to cents', () => {
    assert.equal(mapper.price({ price: 10, tax_class_id: 2 }, cfg), 10.83);
});

test('price rounds half-cents up despite float noise', () => {
    assert.equal(mapper.price({ price: 2.5, tax_class_id: 3 }, { ...cfg, taxFactors: { 3: 1.19 } }), 2.98);
});

test('price reads the first element of an array tax class', () => {
    assert.equal(mapper.price({ price: 10, tax_class_id: [2] }, cfg), 10.83);
});

test('price without a known factor is untaxed', () => {
    assert.equal(mapper.price({ price: 10, tax_class_id: 9 }, cfg), 10);
    assert.equal(mapper.price({ price: 10 }, { priceField: 'price' }), 10);
});

test('price converts with the currency rate after tax rounding', () => {
    const value = mapper.price({ price: 10, tax_class_id: 2 }, { ...cfg, currencyRate: 0.5 });

    assert.ok(Math.abs(value - 5.415) < 1e-9);
});

test('price is null when missing, zero is kept', () => {
    assert.equal(mapper.price({}, cfg), null);
    assert.equal(mapper.price({ price: null }, cfg), null);
    assert.equal(mapper.price({ price: 'x' }, cfg), null);
    assert.equal(mapper.price({ price: 0, tax_class_id: 2 }, cfg), 0);
});

test('title falls back name → title → sku → empty', () => {
    assert.equal(mapper.title({ name: 'Bag', sku: 'B1' }), 'Bag');
    assert.equal(mapper.title({ name: '', title: 'T', sku: 'B1' }), 'T');
    assert.equal(mapper.title({ sku: 42 }), '42');
    assert.equal(mapper.title({}), '');
});

test('productUrl joins base, url_key and suffix with single slashes', () => {
    assert.equal(mapper.productUrl({ url_key: 'bag' }, cfg), 'https://magento.loc/bag.html');
    assert.equal(
        mapper.productUrl({ url_key: '/bag' }, { baseUrl: 'https://magento.loc///' }),
        'https://magento.loc/bag'
    );
});

test('productUrl is null without url_key', () => {
    assert.equal(mapper.productUrl({}, cfg), null);
    assert.equal(mapper.productUrl({ url_key: '' }, cfg), null);
});

test('imageUrl puts the thumbnail path into the template token', () => {
    const tpl = { imageUrlTemplate: 'https://magento.loc/media/catalog/product/cache/abc/__DMLAB_IMAGE__' };

    assert.equal(mapper.imageUrl({ thumbnail: '/m/b/bag.jpg' }, tpl), 'https://magento.loc/media/catalog/product/cache/abc/m/b/bag.jpg');
    assert.equal(mapper.imageUrl({ thumbnail: 'm/b/bag.jpg' }, tpl), 'https://magento.loc/media/catalog/product/cache/abc/m/b/bag.jpg');
    assert.equal(mapper.imageUrl({ thumbnail: '//m/b/$&.jpg' }, tpl), 'https://magento.loc/media/catalog/product/cache/abc/m/b/$&.jpg');
});

test('imageUrl keeps a query-string template intact', () => {
    const tpl = { imageUrlTemplate: 'https://magento.loc/media/catalog/product/__DMLAB_IMAGE__?width=75&height=75' };

    assert.equal(mapper.imageUrl({ thumbnail: '/m/b/bag.jpg' }, tpl), 'https://magento.loc/media/catalog/product/m/b/bag.jpg?width=75&height=75');
});

test('imageUrl falls back to the media base, then the raw path; skips no_selection', () => {
    assert.equal(mapper.imageUrl({ thumbnail: '/m/b/bag.jpg' }, cfg), 'https://magento.loc/media/catalog/product/m/b/bag.jpg');
    assert.equal(mapper.imageUrl({ thumbnail: '/m/b/bag.jpg' }, { ...cfg, imageUrlTemplate: 'https://x/no-token' }), 'https://magento.loc/media/catalog/product/m/b/bag.jpg');
    assert.equal(mapper.imageUrl({ thumbnail: '/m/b/bag.jpg' }, {}), '/m/b/bag.jpg');
    assert.equal(mapper.imageUrl({ thumbnail: 'no_selection' }, cfg), null);
    assert.equal(mapper.imageUrl({ image: '/m/b/bag.jpg' }, cfg), null);
});

test('map builds the item and formats the display price', () => {
    const item = mapper.map(
        { name: 'Bag', sku: 'B1', price: 10, tax_class_id: 2, thumbnail: '/b.jpg', url_key: 'bag' },
        cfg,
        (amount) => '$' + amount.toFixed(2)
    );

    assert.deepEqual(item, {
        title: 'Bag',
        sku: 'B1',
        price: '$10.83',
        image: 'https://magento.loc/media/catalog/product/b.jpg',
        url: 'https://magento.loc/bag.html'
    });
});

test('map honours the display-field toggles and omits a missing url', () => {
    const item = mapper.map(
        { name: 'Bag', sku: 'B1', price: 10, thumbnail: '/b.jpg' },
        { ...cfg, displayFields: { title: true } },
        () => assert.fail('price must not be formatted')
    );

    assert.deepEqual(item, { title: 'Bag' });
});
