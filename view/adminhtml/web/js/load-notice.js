define([
    'jquery',
    'mage/translate'
], function ($, $t) {
    'use strict';

    return function (config) {
        var releaseId = config.releaseId;
        var changeCount = config.changeCount;
        var releaseName = config.releaseName || 'Release #' + releaseId;
        var dismissUrl = config.dismissUrl;

        if (!releaseId) return;

        var message = $t('%1 field(s) loaded from "%2". Review the changes and save when ready.')
            .replace('%1', changeCount)
            .replace('%2', releaseName);

        if (config.quick) {
            message = changeCount > 0
                ? $t('Your Quick Preview edit is back in the form. To keep it, choose MageDrop › Save & Stage.')
                : $t('That Quick Preview is no longer available, so nothing was loaded into the form.');
        }

        var html = '<div id="magedrop-load-notice" style="' +
            'background: #0f172a;' +
            'color: #fff;' +
            'padding: 12px 20px;' +
            'margin: 0 0 20px;' +
            'border-radius: 6px;' +
            'display: flex;' +
            'align-items: center;' +
            'justify-content: space-between;' +
            'font-size: 13px;' +
            '">' +
            '<span>' +
            '<strong>MageDrop:</strong> ' +
            message +
            '</span>' +
            '<a href="' + dismissUrl + '" style="' +
            'color: #fff;' +
            'opacity: 0.8;' +
            'text-decoration: underline;' +
            'margin-left: 20px;' +
            'white-space: nowrap;' +
            '">' + $t('Dismiss') + '</a>' +
            '</div>';

        var $form = $('.page-main-actions');
        if ($form.length) {
            $form.after(html);
        } else {
            $('#container').prepend(html);
        }
    };
});
