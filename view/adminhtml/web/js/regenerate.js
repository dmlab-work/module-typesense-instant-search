/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 *
 * Admin config "Regenerate key" button handler, kept out of the page as a static
 * component so no inline `<script>` is emitted. Bound via `data-mage-init`; the
 * controller URL and CSRF form key travel in the button's data attributes.
 * On confirmed click it POSTs to the regenerate controller, reports the outcome and
 * reloads on success so the status field reflects the new keys.
 */
define(['jquery', 'Magento_Ui/js/modal/confirm', 'mage/translate'], function ($, confirm, $t) {
    'use strict';

    function post(button) {
        var body = new FormData();

        button.disabled = true;
        body.append('form_key', button.dataset.formKey);
        fetch(button.dataset.regenerateUrl, {
            method: 'POST',
            body: body,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
            .then(function (r) {
                return r.json();
            })
            .then(function (data) {
                window.alert(data.message || (data.success ? $t('Key regenerated.') : $t('Regeneration failed.')));

                if (data.success) {
                    window.location.reload();
                }
            })
            .catch(function () {
                window.alert($t('Regeneration request failed.'));
            })
            .finally(function () {
                button.disabled = false;
            });
    }

    return function (config, element) {
        $(element).on('click', function () {
            var button = this;

            confirm({
                content: $t('Regenerating revokes the current storefront keys and flushes the full-page cache. Continue?'),
                actions: {
                    confirm: function () {
                        post(button);
                    }
                }
            });
        });
    };
});
