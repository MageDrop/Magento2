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

        var html = '<div id="magedrop-load-notice" style="' +
            'background: linear-gradient(135deg, #6366f1, #9333ea);' +
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
            $t('%1 field(s) loaded from "%2". Review the changes and save when ready.')
                .replace('%1', changeCount)
                .replace('%2', releaseName) +
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
