/**
 * Copyright © MageDevGroup. All rights reserved.
 *
 * Typesense instant search — a drop-in replacement for Magento's stock `quickSearch`
 * widget. It EXTENDS the stock widget (`Magento_Search/js/form-mini`) rather than
 * reimplementing it, so keyboard navigation, submit / "view all results", the active
 * state and the ARIA wiring are all inherited unchanged. Only the data source is
 * overridden.
 *
 * Two query modes, one render path — the debounce, min-length gate and dropdown
 * rendering are identical; only the fetch target differs:
 *  - proxy (default): `GET <endpoint>?q=…` on the same-origin Magento controller, which
 *    returns items already mapped server-side (Typesense stays private).
 *  - direct: `GET <host>/collections/<alias>/documents/search` straight to Typesense with
 *    the derived scoped key, mapping hit documents to the same item shape client-side.
 *
 * Config is read from the JSON the InstantSearchConfig view model emits. Fail closed:
 * whenever it is absent or unusable for the active mode, every override delegates to
 * `_super()`, leaving Magento's native autocomplete untouched. Any network / parse error
 * degrades the same way, and the form submit always falls through to the native results page.
 */
define([
    'jquery',
    'Magento_Catalog/js/price-utils',
    'Magento_Search/js/form-mini'
], function ($, priceUtils) {
    'use strict';

    var CONFIG_ELEMENT_ID = 'magedevgroup-typesense-instant-search-config';

    /**
     * Read and parse the view-model config emitted into the page, or null when the
     * widget must not run (element absent, empty, or unparseable → fail closed).
     *
     * @return {Object|null}
     */
    function readInstantConfig() {
        var el = document.getElementById(CONFIG_ELEMENT_ID),
            raw = el ? (el.textContent || '').trim() : '';

        if (raw === '') {
            return null;
        }

        try {
            return JSON.parse(raw);
        } catch (e) {
            return null;
        }
    }

    $.widget('mage.quickSearch', $.mage.quickSearch, {

        /** @inheritdoc */
        _create: function () {
            this.instantConfig = readInstantConfig();

            if (this.instantConfig) {
                // Apply our config onto the inherited stock options BEFORE `_super()` runs `_bind`,
                // which wires the keystroke debounce off `suggestionDelay`. `minSearchLength` is read
                // per keystroke, so ordering only matters for the debounce.
                if (typeof this.instantConfig.minQueryLength === 'number') {
                    this.options.minSearchLength = this.instantConfig.minQueryLength;
                }

                if (typeof this.instantConfig.debounceMs === 'number') {
                    this.options.suggestionDelay = this.instantConfig.debounceMs;
                }
            }

            this._super();

            // Product rows navigate to the product page, not a search. When a row is highlighted
            // (hover / arrow keys) and Enter submits the form, go to that product instead of running
            // a search. A submit with no highlighted row (or "view all") still runs the search.
            if (this.instantConfig) {
                var self = this;

                this.searchForm.on('submit', function (e) {
                    var selected = self.responseList.selected;

                    if (self._instantEnabled() && selected && selected.length && selected.attr('data-url')) {
                        e.preventDefault();
                        window.location.assign(selected.attr('data-url'));
                    }
                });
            }
        },

        /**
         * True only when a usable config was emitted for the active mode: proxy needs the
         * same-origin endpoint, direct needs host + scoped key + collection.
         *
         * @return {Boolean}
         */
        _instantEnabled: function () {
            var config = this.instantConfig;

            if (!config) {
                return false;
            }

            if (config.mode === 'direct') {
                return !!(config.host && config.apiKey && config.collection);
            }

            return !!config.endpoint;
        },

        /**
         * Query Typesense (proxy or direct) instead of the stock PHP autocomplete endpoint.
         * Delegates to the native behaviour whenever instant search is not usable.
         * @private
         */
        _onPropertyChange: function () {
            var value = this.element.val(),
                minLength = parseInt(this.options.minSearchLength, 10);

            if (!this._instantEnabled()) {
                return this._super();
            }

            this.submitBtn.disabled = true;

            if (value.length < minLength) {
                this._resetResponseList(true);
                this.autoComplete.hide();
                this._updateAriaHasPopup(false);
                this.element.removeAttr('aria-activedescendant');

                return;
            }

            this.submitBtn.disabled = false;
            this._search(value);
        },

        /**
         * Fetch results for the current term and render them. Branches only on the fetch
         * target — the proxy returns mapped items, direct returns raw hits mapped here — so
         * both feed the identical render path. Any failure hides the dropdown and leaves the
         * native form submit as the path to results.
         * @private
         * @param {String} term
         */
        _search: function (term) {
            var request = this._buildRequest(term),
                self = this;

            fetch(request.url, request.options).then(function (response) {
                if (!response.ok) {
                    throw new Error('Instant search responded ' + response.status);
                }

                return response.json();
            }).then(function (data) {
                // Ignore stale responses if the term has since changed.
                if (self.element.val() !== term) {
                    return;
                }
                self._renderItems(self._toItems(data));
            }).catch(function () {
                // Ignore stale failures: a slow error for an old term must not clear results
                // already rendered for a newer one.
                if (self.element.val() !== term) {
                    return;
                }
                // Degrade silently: keep the input usable, submit falls through to native search.
                self._resetResponseList(true);
                self.autoComplete.hide();
                self._updateAriaHasPopup(false);
                self.element.removeAttr('aria-activedescendant');
            });
        },

        /**
         * Build the mode-specific fetch request (URL + options) for a term.
         * @private
         * @param {String} term
         * @return {{url: String, options: Object}}
         */
        _buildRequest: function (term) {
            var config = this.instantConfig,
                params;

            if (config.mode === 'direct') {
                params = {
                    q: term,
                    query_by: config.queryBy, //eslint-disable-line camelcase
                    per_page: config.resultLimit || 10 //eslint-disable-line camelcase
                };

                if (config.queryByWeights) {
                    params.query_by_weights = config.queryByWeights; //eslint-disable-line camelcase
                }

                return {
                    url: 'https://' + config.host + '/collections/' +
                        encodeURIComponent(config.collection) + '/documents/search?' + $.param(params),
                    options: {
                        headers: { 'X-TYPESENSE-API-KEY': config.apiKey }
                    }
                };
            }

            return {
                url: config.endpoint +
                    (config.endpoint.indexOf('?') === -1 ? '?' : '&') + $.param({ q: term }),
                options: {}
            };
        },

        /**
         * Normalise a mode's response into the shared item shape ({title?, sku?, price?, image?}):
         * proxy returns mapped items verbatim; direct maps its hit documents client-side.
         * @private
         * @param {Object} data the parsed response body
         * @return {Array}
         */
        _toItems: function (data) {
            var self = this;

            if (this.instantConfig.mode === 'direct') {
                return $.map((data && data.hits) || [], function (hit) {
                    return self._mapDocument(hit.document || {});
                });
            }

            return (data && data.items) || [];
        },

        /**
         * Map a Typesense hit document to the shared item shape, honouring the admin display-field
         * toggles — the JS twin of the PHP ResultMapper so both modes render identically.
         * @private
         * @param {Object} doc the Typesense hit document
         * @return {Object}
         */
        _mapDocument: function (doc) {
            var fields = this.instantConfig.displayFields || {},
                priceField = this.instantConfig.priceField,
                title = this._hitTitle(doc),
                item = {};

            if (fields.title && title !== '') {
                item.title = title;
            }

            if (fields.sku && doc.sku) {
                item.sku = String(doc.sku);
            }

            if (fields.price && priceField && (doc[priceField] || doc[priceField] === 0)) {
                item.price = this._formatPrice(doc[priceField]);
            }

            if (fields.image && doc.image && doc.image !== 'no_selection') {
                item.image = this._imageUrl(doc.image);
            }

            if (doc.url_key) {
                item.url = this._productUrl(doc.url_key);
            }

            return item;
        },

        /**
         * Build a product page URL from a hit's `url_key`, mirroring the PHP ResultMapper so both
         * modes link rows to the same product page.
         * @private
         * @param {String} urlKey
         * @return {String}
         */
        _productUrl: function (urlKey) {
            var base = String(this.instantConfig.baseUrl || '').replace(/\/+$/, ''),
                suffix = this.instantConfig.productUrlSuffix || '';

            return base + '/' + String(urlKey).replace(/^\/+/, '') + suffix;
        },

        /**
         * Best title for a hit document: `name`, then `title`, then `sku` — the JS twin of
         * `ResultMapper::title()` so both modes pick the identical dropdown title.
         * @private
         * @param {Object} document
         * @return {String}
         */
        _hitTitle: function (document) {
            var fields = ['name', 'title', 'sku'],
                i,
                value;

            for (i = 0; i < fields.length; i++) {
                value = document[fields[i]];

                if (value !== undefined && value !== null && String(value) !== '') {
                    return String(value);
                }
            }

            return '';
        },

        /**
         * Format a raw numeric price with the store's currency pattern (symbol, precision, grouping) —
         * the JS twin of `ResultMapper`'s `PriceCurrencyInterface::format`, so both modes render the
         * same localized price. Falls back to the raw value when no price format was emitted.
         * @private
         * @param {Number|String} amount raw price from the hit document
         * @return {String}
         */
        _formatPrice: function (amount) {
            var format = this.instantConfig.priceFormat;

            if (!format) {
                return String(amount);
            }

            return priceUtils.formatPrice(amount, format);
        },

        /**
         * Resolve a raw catalog `image` value (e.g. `/m/y/img.jpg`) to an absolute URL under the
         * product media base, mirroring Magento's `Media\Config::getMediaUrl`. Without a base the
         * raw value is returned unchanged (fail soft).
         * @private
         * @param {String} image raw EAV image value
         * @return {String}
         */
        _imageUrl: function (image) {
            var base = this.instantConfig.mediaUrl;

            image = String(image);

            return base ? base.replace(/\/+$/, '') + '/' + image.replace(/^\/+/, '') : image;
        },

        /**
         * Build one dropdown row from a normalised item. Values go through jQuery `.text()` /
         * `.attr()` so data is escaped. The `.qs-option-name` span is always present so the
         * inherited keyboard navigation can fill the input from its text.
         * @private
         * @param {Object} item {title?, sku?, price?, image?}
         * @param {Number} index
         * @return {jQuery}
         */
        _buildRow: function (item, index) {
            var row = $('<li role="option"></li>')
                    .attr('id', 'qs-option-' + index)
                    .attr('data-url', item.url || ''),
                name = $('<span class="qs-option-name"></span>').text(item.title || '');

            if (item.image) {
                row.append(
                    $('<span class="qs-option-image"></span>')
                        .append($('<img alt="" />').attr('src', item.image))
                );
            }

            row.append(name);

            if (item.sku) {
                row.append($('<span class="qs-option-sku"></span>').text(String(item.sku)));
            }

            if (item.price || item.price === 0) {
                row.append($('<span class="qs-option-price"></span>').text(String(item.price)));
            }

            return row;
        },

        /**
         * Render normalised items into the stock `#search_autocomplete` container, reusing the
         * inherited dropdown structure so keyboard navigation and selection keep working.
         * @private
         * @param {Array} items
         */
        _renderItems: function (items) {
            var dropdown = $('<ul role="listbox"></ul>'),
                self = this;

            if (!items.length) {
                this._resetResponseList(true);
                this.autoComplete.hide();
                this._updateAriaHasPopup(false);
                this.element.removeAttr('aria-activedescendant');

                return;
            }

            $.each(items, function (index, item) {
                dropdown.append(self._buildRow(item, index));
            });

            this._resetResponseList(true);

            this.responseList.indexList = this.autoComplete.html(dropdown)
                .css({ position: 'absolute', width: this.element.outerWidth() })
                .show()
                .find(this.options.responseFieldElements + ':visible');

            this.element.removeAttr('aria-activedescendant');
            this._updateAriaHasPopup(this.responseList.indexList.length > 0);

            this.responseList.indexList
                .on('click', function (e) {
                    var row = $(e.currentTarget),
                        url = row.attr('data-url');

                    self.responseList.selected = row;

                    if (url) {
                        window.location.assign(url);

                        return;
                    }

                    self.searchForm.trigger('submit');
                })
                .on('mouseenter mouseleave', function (e) {
                    self.responseList.indexList.removeClass(self.options.selectClass);
                    $(e.target).addClass(self.options.selectClass);
                    self.responseList.selected = $(e.target);
                    self.element.attr('aria-activedescendant', $(e.target).attr('id'));
                });
        }
    });

    return $.mage.quickSearch;
});
