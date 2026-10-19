/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 *
 * Minimal AMD `define` shim: evaluates a module file with `vm` (same realm, so results compare with
 * `deepStrictEqual`) and returns its factory result.
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const JS_ROOT = path.resolve(__dirname, '../../view/frontend/web/js');

/**
 * @param {string} relativePath module path under view/frontend/web/js, e.g. 'instant-search/hit-mapper.js'
 * @param {Object<string,*>} deps dependency name → value
 * @return {*}
 */
function load(relativePath, deps = {}) {
    const file = path.join(JS_ROOT, relativePath);
    let result;
    const define = (names, factory) => {
        result = factory(...names.map((name) => {
            if (!(name in deps)) {
                throw new Error(`Unmocked AMD dependency: ${name}`);
            }

            return deps[name];
        }));
    };

    vm.runInThisContext(`(function (define) {${fs.readFileSync(file, 'utf8')}\n})`, { filename: file })(define);

    return result;
}

module.exports = { load };
