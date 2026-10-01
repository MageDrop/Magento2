define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'mage/translate'
], function ($, alert, $t) {
    'use strict';

    /**
     * Shows the Quick Preview link after the post-stage redirect.
     */
    return function (config) {
        var previewUrl = config.previewUrl;
        var changeCount = config.changeCount || 0;

        if (!previewUrl) {
            return;
        }

        var safeUrl = $('<span>').text(previewUrl).html();

        alert({
            title: $t('Preview Ready'),
            content: '<p>' + $t('%1 change(s) detected.').replace('%1', changeCount) + '</p>' +
                     '<p style="margin-top: 10px;">' +
                     '<a href="' + safeUrl + '" target="_blank" id="magedrop-open-preview" ' +
                     'style="display: inline-block; background: #0f172a; color: white; padding: 8px 20px; border-radius: 5px; ' +
                     'text-decoration: none; font-weight: 600; font-size: 14px;">' +
                     $t('Open Preview') + '</a></p>' +
                     '<p style="margin-top: 12px;">' +
                     '<input type="text" id="magedrop-preview-url" value="' + safeUrl + '" readonly ' +
                     'style="width: 100%; padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; color: #666; background: #f9f9f9;">' +
                     '</p>' +
                     '<p style="margin-top: 6px;">' +
                     '<button type="button" id="magedrop-copy-url" ' +
                     'style="background: none; border: none; color: #0f172a; cursor: pointer; font-size: 12px; padding: 0;">' +
                     $t('Copy link') + '</button>' +
                     '<span id="magedrop-copy-confirm" style="display: none; color: #16a34a; font-size: 12px; margin-left: 8px;">' +
                     $t('Copied!') + '</span></p>' +
                     '<p style="margin-top: 10px; color: #666; font-size: 12px;">' +
                     $t('Share this link with anyone to preview changes. No login required.') + '</p>',
            modalClass: 'magedrop-preview-modal'
        });

        $(document).off('click', '#magedrop-copy-url');
        $(document).on('click', '#magedrop-copy-url', function () {
            var urlInput = document.getElementById('magedrop-preview-url');
            urlInput.select();
            navigator.clipboard.writeText(urlInput.value).then(function () {
                $('#magedrop-copy-confirm').show().delay(2000).fadeOut();
            });
        });
    };
});
