/**
 * Copyright © DMLab. All rights reserved.
 *
 * Replace Magento's stock `quickSearch` widget in place. Magento_Search binds
 * `quickSearch` to `#search` via `data-mage-init`; remapping the alias swaps our
 * implementation in while it inherits the stock options (formSelector /
 * destinationSelector / minSearchLength). No template or layout override — the
 * module stays theme-agnostic on any theme using the stock search form.
 */
var config = {
    map: {
        '*': {
            quickSearch: 'DmLab_TypesenseInstantSearch/js/instant-search',
            'Magento_Search/form-mini': 'DmLab_TypesenseInstantSearch/js/instant-search'
        }
    }
};
