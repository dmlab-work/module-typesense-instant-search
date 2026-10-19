/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 *
 * Row-selection rules of the instant-search dropdown. No dependencies (unit-tested under node).
 */
define([], function () {
    'use strict';

    /** HOME, END, UP, DOWN: the inherited keys that move the selection. */
    var NAVIGATION_KEYS = [35, 36, 38, 40];

    /**
     * Whether Enter on the selected row opens its product instead of submitting the search: only when
     * the row was chosen by keyboard, or by the pointer that is still over it.
     *
     * @param {{url: String, hovered: Boolean}|null} selectedRow
     * @param {String|null} source 'keyboard' | 'pointer'
     * @return {Boolean}
     */
    function shouldNavigate(selectedRow, source) {
        if (!selectedRow || !selectedRow.url) {
            return false;
        }

        return source === 'keyboard' || (source === 'pointer' && !!selectedRow.hovered);
    }

    /**
     * @param {Number} keyCode
     * @return {Boolean}
     */
    function isNavigationKey(keyCode) {
        return NAVIGATION_KEYS.indexOf(keyCode) !== -1;
    }

    /**
     * Text of a row's `.qs-option-name`, which the inherited arrow-key handler copies into the input,
     * so it is never empty: the title, else the sku, else the term.
     *
     * @param {{title?: String, sku?: String}} item
     * @param {String} term
     * @return {String}
     */
    function optionName(item, term) {
        return String(item.title || item.sku || term || '');
    }

    return {
        shouldNavigate: shouldNavigate,
        isNavigationKey: isNavigationKey,
        optionName: optionName
    };
});
