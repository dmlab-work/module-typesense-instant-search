/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 *
 * Request URLs and the transport state machine of the instant-search widget. No dependencies
 * (unit-tested under node).
 */
define([], function () {
    'use strict';

    /** How long the widget stays silent after the proxy reports Typesense unavailable. */
    var PAUSE_MS = 30000;

    /**
     * @param {Object<string,*>} params
     * @return {String}
     */
    function query(params) {
        return Object.keys(params).map(function (name) {
            return encodeURIComponent(name) + '=' + encodeURIComponent(params[name]);
        }).join('&');
    }

    /**
     * @param {Object} cfg widget config
     * @return {Boolean}
     */
    function directUsable(cfg) {
        return cfg.mode === 'direct' && !!(cfg.host && cfg.apiKey && cfg.collection);
    }

    return {
        PAUSE_MS: PAUSE_MS,

        /**
         * Request for a term. Direct passes the key as a query param and sets no headers, so the GET
         * is a CORS simple request (no preflight).
         *
         * @param {String} term
         * @param {Object} cfg widget config
         * @param {String} mode 'direct' | 'proxy'
         * @return {{url: String}}
         */
        build: function (term, cfg, mode) {
            var params;

            if (mode === 'direct') {
                params = {
                    q: term,
                    query_by: cfg.queryBy, //eslint-disable-line camelcase
                    per_page: cfg.resultLimit || 10 //eslint-disable-line camelcase
                };

                if (cfg.queryByWeights) {
                    params.query_by_weights = cfg.queryByWeights; //eslint-disable-line camelcase
                }
                params['x-typesense-api-key'] = cfg.apiKey;

                return {
                    url: 'https://' + cfg.host + '/collections/' + encodeURIComponent(cfg.collection) +
                        '/documents/search?' + query(params)
                };
            }

            return {
                url: cfg.endpoint + (cfg.endpoint.indexOf('?') === -1 ? '?' : '&') + query({ q: term })
            };
        },

        /**
         * @param {Object} cfg widget config
         * @return {{mode: String, pausedUntil: Number}}
         */
        initialState: function (cfg) {
            return { mode: directUsable(cfg) ? 'direct' : 'proxy', pausedUntil: 0 };
        },

        /**
         * Next transport state. A direct failure (network error, non-2xx, timeout) switches to the proxy
         * for the rest of the page; a proxy `unavailable` answer pauses requests for PAUSE_MS.
         *
         * @param {{mode: String, pausedUntil: Number}} state
         * @param {{failed?: Boolean, unavailable?: Boolean, now: Number}} outcome
         * @return {{mode: String, pausedUntil: Number}}
         */
        nextMode: function (state, outcome) {
            if (state.mode === 'direct' && outcome.failed) {
                return { mode: 'proxy', pausedUntil: state.pausedUntil };
            }

            if (state.mode === 'proxy' && outcome.unavailable) {
                return { mode: 'proxy', pausedUntil: outcome.now + PAUSE_MS };
            }

            return state;
        },

        /**
         * @param {{pausedUntil: Number}} state
         * @param {Number} now
         * @return {Boolean}
         */
        isPaused: function (state, now) {
            return now < state.pausedUntil;
        }
    };
});
