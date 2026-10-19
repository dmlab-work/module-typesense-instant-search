/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 *
 * Maps a direct-mode Typesense hit document to the dropdown item shape — the JS twin of the
 * PHP ResultMapper, so both modes render identically. No dependencies (unit-tested under node).
 */
define([], function () {
    'use strict';

    /** Path token in `imageUrlTemplate` (see InstantSearchConfig::IMAGE_TOKEN). */
    var IMAGE_TOKEN = '/__DMLAB_IMAGE__';

    /**
     * Half-up to cents like Magento's PriceCurrency::round(); the 15-digit pass drops float noise
     * (2.5 × 1.19 = 2.9749999999999996 must round to 2.98).
     *
     * @param {Number} value
     * @return {Number}
     */
    function round2(value) {
        return Math.round(Number((value * 100).toPrecision(15))) / 100;
    }

    /**
     * @param {*} value
     * @return {Boolean}
     */
    function isBlank(value) {
        return value === undefined || value === null || String(value) === '';
    }

    return {
        /**
         * Display price: the indexed base price × the guest tax factor of its class, rounded like
         * Magento's tax display, then converted to the current currency. Null when the hit has no price.
         *
         * @param {Object} doc
         * @param {Object} cfg widget config (`priceField`, `taxFactors`, `currencyRate`)
         * @return {Number|null}
         */
        price: function (doc, cfg) {
            var amount = doc[cfg.priceField || 'price'],
                classId = Array.isArray(doc.tax_class_id) ? doc.tax_class_id[0] : doc.tax_class_id,
                factors = cfg.taxFactors || {},
                factor = factors[classId] !== undefined ? Number(factors[classId]) : 1,
                rate = cfg.currencyRate ? Number(cfg.currencyRate) : 1;

            if (isBlank(amount) || isNaN(Number(amount))) {
                return null;
            }

            return round2(Number(amount) * factor) * rate;
        },

        /**
         * Best title for a hit: `name`, then `title`, then `sku` (as `ResultMapper::title()`).
         *
         * @param {Object} doc
         * @return {String}
         */
        title: function (doc) {
            var fields = ['name', 'title', 'sku'],
                i;

            for (i = 0; i < fields.length; i++) {
                if (!isBlank(doc[fields[i]])) {
                    return String(doc[fields[i]]);
                }
            }

            return '';
        },

        /**
         * Product page URL from a hit's `url_key`, or null without one.
         *
         * @param {Object} doc
         * @param {Object} cfg widget config (`baseUrl`, `productUrlSuffix`)
         * @return {String|null}
         */
        productUrl: function (doc, cfg) {
            var base = String(cfg.baseUrl || '').replace(/\/+$/, '');

            if (isBlank(doc.url_key)) {
                return null;
            }

            return base + '/' + String(doc.url_key).replace(/^\/+/, '') + (cfg.productUrlSuffix || '');
        },

        /**
         * Resized thumbnail URL: the `thumbnail` path put into `imageUrlTemplate` at its token, else
         * the original under `mediaUrl`, else the raw path. Null when the hit has no image.
         *
         * @param {Object} doc
         * @param {Object} cfg widget config (`imageUrlTemplate`, `mediaUrl`)
         * @return {String|null}
         */
        imageUrl: function (doc, cfg) {
            var image = doc.thumbnail,
                template = cfg.imageUrlTemplate ? String(cfg.imageUrlTemplate) : '',
                base = cfg.mediaUrl;

            if (isBlank(image) || image === 'no_selection') {
                return null;
            }

            image = '/' + String(image).replace(/^\/+/, '');

            if (template.indexOf(IMAGE_TOKEN) !== -1) {
                return template.replace(IMAGE_TOKEN, function () {
                    return image;
                });
            }

            return base ? base.replace(/\/+$/, '') + image : image;
        },

        /**
         * Map a hit document to {title?, sku?, price?, image?, url?}, honouring the display-field toggles.
         *
         * @param {Object} doc
         * @param {Object} cfg widget config
         * @param {Function} formatPrice formats a numeric display price
         * @return {Object}
         */
        map: function (doc, cfg, formatPrice) {
            var fields = cfg.displayFields || {},
                title = this.title(doc),
                price = this.price(doc, cfg),
                image = this.imageUrl(doc, cfg),
                url = this.productUrl(doc, cfg),
                item = {};

            if (fields.title && title !== '') {
                item.title = title;
            }

            if (fields.sku && !isBlank(doc.sku)) {
                item.sku = String(doc.sku);
            }

            if (fields.price && price !== null) {
                item.price = formatPrice(price);
            }

            if (fields.image && image !== null) {
                item.image = image;
            }

            if (url !== null) {
                item.url = url;
            }

            return item;
        }
    };
});
