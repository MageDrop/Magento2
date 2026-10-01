define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'mage/translate',
    'uiRegistry'
], function ($, alert, $t, registry) {
    'use strict';

    /**
     * Quick Preview: submit the real admin form flagged for preview. The Save
     * controller plugin diffs the POST, creates a temporary preview release and
     * redirects back; quick-preview-result.js then shows the link.
     */
    return function (config, element) {
        var formName = config.formName;

        $(element).on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            var form = registry.get(formName);

            if (!form || typeof form.save !== 'function') {
                alert({content: $t('Could not access the form.')});
                return;
            }

            $(element).text($t('Creating preview...')).prop('disabled', true);

            form.save(undefined, {
                magedrop_quick_preview: 1
            });

            // If validation failed the page did not navigate — restore the button
            setTimeout(function () {
                $(element).text($t('Quick Preview')).prop('disabled', false);
            }, 3000);
        });
    };
});
