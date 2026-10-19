/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 *
 * Typesense instant search — a drop-in replacement for Magento's stock `quickSearch`
 * widget. It EXTENDS the stock widget (`Magento_Search/js/form-mini`) rather than
 * reimplementing it, so keyboard navigation, submit / "view all results" and the active
 * state are inherited. It overrides the data source, the row rendering and the selection
 * state (hover, `aria-selected`, a live region announcing the result count).
 *
 * Two query modes, one render path; URLs and mode switching live in `instant-search/request`:
 *  - proxy (default): `GET <endpoint>?q=…` on the same-origin Magento controller, which
 *    returns items already mapped server-side (Typesense stays private).
 *  - direct: `GET <host>/collections/<alias>/documents/search` straight to Typesense with
 *    the derived scoped key, mapping hit documents to the same item shape client-side.
 *    A failure switches the page to the proxy.
 *
 * Config is read from the JSON the InstantSearchConfig view model emits. Fail closed:
 * whenever it is absent, every override delegates to `_super()`, leaving Magento's native
 * autocomplete untouched. The form submit always falls through to the native results page.
 */
define([
    'jquery',
    'mage/translate',
    'Magento_Catalog/js/price-utils',
    'DmLab_TypesenseInstantSearch/js/instant-search/hit-mapper',
    'DmLab_TypesenseInstantSearch/js/instant-search/request',
    'DmLab_TypesenseInstantSearch/js/instant-search/selection',
    'Magento_Search/js/form-mini'
], function ($, $t, priceUtils, hitMapper, request, selection) {
    'use strict';

    var CONFIG_ELEMENT_ID = 'dmlab-typesense-instant-search-config',
        LISTBOX_ID = 'dmlab-qs-listbox',
        REQUEST_TIMEOUT_MS = 2000;

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

            // Enter on a row chosen by keyboard, or under the pointer, opens that product; any other
            // submit runs the search.
            if (this.instantConfig) {
                var self = this;

                this._status = $('<span role="status" aria-live="polite" class="dmlab-qs-status"></span>')
                    .insertAfter(this.autoComplete);

                this.searchForm.on('submit', function (e) {
                    var selected = self.responseList.selected;

                    if (self._instantEnabled() && selected && selected.length && selection.shouldNavigate({
                        url: selected.attr('data-url'),
                        hovered: selected[0].matches(':hover')
                    }, self._selectionSource)) {
                        e.preventDefault();
                        window.location.assign(selected.attr('data-url'));
                    }
                });
            }
        },

        /**
         * Inherited keyboard handling, then record a keyboard selection and sync `aria-selected`.
         * @private
         * @param {Event} e
         * @return {Boolean|undefined}
         */
        _onKeyDown: function (e) {
            var rows = this.responseList.indexList,
                selected = this.responseList.selected,
                result;

            // Stock UP dereferences `selected`; with none (after hover-out) wrap from the first row to the last.
            if (this._instantEnabled() && (e.keyCode || e.which) === $.ui.keyCode.UP && rows && rows.length &&
                !(selected && selected.length)) {
                this.responseList.selected = this._getFirstVisibleElement().addClass(this.options.selectClass);
            }

            result = this._super(e);

            if (!this._instantEnabled()) {
                return result;
            }

            if (selection.isNavigationKey(e.keyCode || e.which) && this.responseList.selected) {
                this._selectionSource = 'keyboard';
            }

            this._syncSelection();

            return result;
        },

        /**
         * Make the selected row the only one with the select class and `aria-selected="true"`.
         * @private
         */
        _syncSelection: function () {
            var rows = this.responseList.indexList,
                selected = this.responseList.selected;

            if (!rows) {
                return;
            }

            rows.attr('aria-selected', 'false');

            if (selected && selected.length) {
                rows.not(selected).removeClass(this.options.selectClass);
                selected.addClass(this.options.selectClass).attr('aria-selected', 'true');
            } else {
                rows.removeClass(this.options.selectClass);
            }
        },

        /**
         * @private
         */
        _clearSelection: function () {
            this.responseList.selected = null;
            this._selectionSource = null;
            this.element.removeAttr('aria-activedescendant');
            this._syncSelection();
        },

        /**
         * Set the live-region text announced to screen readers.
         * @private
         * @param {String} text
         */
        _announce: function (text) {
            if (this._status) {
                this._status.text(text);
            }
        },

        /**
         * True only when a usable config was emitted: the same-origin proxy endpoint is always present
         * (it is also the direct-mode fallback).
         *
         * @return {Boolean}
         */
        _instantEnabled: function () {
            return !!(this.instantConfig && this.instantConfig.endpoint);
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
                this._abortInFlight();
                this._hideDropdown();

                return;
            }

            this.submitBtn.disabled = false;
            this._search(value);
        },

        /**
         * Fetch results for the current term and render them. A direct failure switches the page to the
         * proxy and retries the term there once; a proxy `unavailable` answer pauses requests. Any other
         * failure hides the dropdown and leaves the native form submit as the path to results.
         * @private
         * @param {String} term
         */
        _search: function (term) {
            var self = this,
                mode;

            this._transport = this._transport || request.initialState(this.instantConfig);
            mode = this._transport.mode;
            this._abortInFlight();

            if (request.isPaused(this._transport, Date.now())) {
                this._hideDropdown();

                return;
            }

            this._fetch(term, mode).then(function (data) {
                if (data === null) {
                    return;
                }

                if (mode === 'proxy' && data.unavailable) {
                    self._transport = request.nextMode(self._transport, { unavailable: true, now: Date.now() });
                    self._hideDropdown();

                    return;
                }

                self._renderItems(self._toItems(data, mode), term);
            }, function () {
                if (mode === 'direct') {
                    self._transport = request.nextMode(self._transport, { failed: true, now: Date.now() });
                    self._search(term);

                    return;
                }

                // Degrade silently: keep the input usable, submit falls through to native search.
                self._hideDropdown();
            });
        },

        /**
         * Fetch one term in a mode with a timeout. Resolves with the parsed body, or null when the request
         * was superseded (a newer term, or the term changed); rejects on network error, non-2xx or timeout.
         * @private
         * @param {String} term
         * @param {String} mode 'direct' | 'proxy'
         * @return {Promise}
         */
        _fetch: function (term, mode) {
            var controller = new AbortController(),
                timer = setTimeout(function () {
                    controller.abort();
                }, REQUEST_TIMEOUT_MS),
                self = this,
                superseded = function () {
                    return self._inFlight !== controller || self.element.val() !== term;
                };

            this._inFlight = controller;

            return fetch(request.build(term, this.instantConfig, mode).url, { signal: controller.signal })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Instant search responded ' + response.status);
                    }

                    return response.json();
                })
                .then(function (data) {
                    return superseded() ? null : data;
                }, function (error) {
                    if (superseded()) {
                        return null;
                    }
                    throw error;
                })
                .finally(function () {
                    clearTimeout(timer);
                    if (self._inFlight === controller) {
                        self._inFlight = null;
                    }
                });
        },

        /**
         * Abort the in-flight request, if any.
         * @private
         */
        _abortInFlight: function () {
            var controller = this._inFlight;

            this._inFlight = null;
            if (controller) {
                controller.abort();
            }
        },

        /**
         * @private
         */
        _hideDropdown: function () {
            this._resetResponseList(true);
            this._selectionSource = null;
            this.autoComplete.hide();
            this._updateAriaHasPopup(false);
            this.element.removeAttr('aria-activedescendant');
            this._announce('');
        },

        /**
         * Normalise a mode's response into the shared item shape ({title?, sku?, price?, image?}):
         * proxy returns mapped items verbatim; direct maps its hit documents client-side.
         * @private
         * @param {Object} data the parsed response body
         * @param {String} mode 'direct' | 'proxy'
         * @return {Array}
         */
        _toItems: function (data, mode) {
            var self = this;

            if (mode === 'direct') {
                return $.map((data && data.hits) || [], function (hit) {
                    return self._mapDocument(hit.document || {});
                });
            }

            return (data && data.items) || [];
        },

        /**
         * Map a Typesense hit document to the shared item shape (see `instant-search/hit-mapper`).
         * @private
         * @param {Object} doc the Typesense hit document
         * @return {Object}
         */
        _mapDocument: function (doc) {
            return hitMapper.map(doc, this.instantConfig, this._formatPrice.bind(this));
        },

        /**
         * Format a display price with the store's currency pattern (symbol, precision, grouping).
         * Falls back to the raw value when no price format was emitted.
         * @private
         * @param {Number} amount display price (taxed, current currency)
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
         * Build one dropdown row from a normalised item. Values go through jQuery `.text()` /
         * `.attr()` so data is escaped. The `.qs-option-name` span always has text so the
         * inherited keyboard navigation can fill the input from it; without a title it is
         * visually hidden.
         * @private
         * @param {Object} item {title?, sku?, price?, image?}
         * @param {Number} index
         * @param {String} term
         * @return {jQuery}
         */
        _buildRow: function (item, index, term) {
            var row = $('<li role="option" aria-selected="false"></li>')
                    .attr('id', 'qs-option-' + index)
                    .attr('data-url', item.url || ''),
                name = $('<span class="qs-option-name"></span>').text(selection.optionName(item, term));

            if (!item.title) {
                name.addClass('dmlab-qs-visually-hidden');
            }

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
         * @param {String} term
         */
        _renderItems: function (items, term) {
            var dropdown = $('<ul role="listbox"></ul>')
                    .attr('id', LISTBOX_ID)
                    .attr('aria-label', $t('Search suggestions')),
                self = this;

            if (!items.length) {
                this._hideDropdown();
                this._announce($t('No suggestions'));

                return;
            }

            $.each(items, function (index, item) {
                dropdown.append(self._buildRow(item, index, term));
            });

            this._resetResponseList(true);
            this._selectionSource = null;

            this.responseList.indexList = this.autoComplete.html(dropdown)
                .css({ position: 'absolute', width: this.element.outerWidth() })
                .show()
                .find(this.options.responseFieldElements + ':visible');

            this.element.removeAttr('aria-activedescendant');
            this._updateAriaHasPopup(this.responseList.indexList.length > 0);
            this._announce($t('%1 suggestions').replace('%1', String(items.length)));

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
                .on('mouseenter', function (e) {
                    var row = $(e.currentTarget);

                    self.responseList.selected = row;
                    self._selectionSource = 'pointer';
                    self.element.attr('aria-activedescendant', row.attr('id'));
                    self._syncSelection();
                })
                .on('mouseleave', function () {
                    self._clearSelection();
                });
        }
    });

    return $.mage.quickSearch;
});
