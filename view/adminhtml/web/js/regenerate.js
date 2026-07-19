/**
 * Copyright © MageDevGroup. All rights reserved.
 *
 * Admin config "Regenerate key" button handler, kept out of the page as a static
 * component so no inline `<script>` is emitted. Bound via `data-mage-init`; the
 * controller URL and CSRF form key travel in the button's data attributes.
 * On click it POSTs to the regenerate controller, reports the outcome and reloads
 * on success so the status field reflects the new key.
 */
define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        $(element).on('click', function () {
            var button = this,
                body = new FormData();

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
                    window.alert(data.message || (data.success ? 'Key regenerated.' : 'Regeneration failed.'));

                    if (data.success) {
                        window.location.reload();
                    }
                })
                .catch(function () {
                    window.alert('Regeneration request failed.');
                })
                .finally(function () {
                    button.disabled = false;
                });
        });
    };
});
