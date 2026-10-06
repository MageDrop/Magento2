define([
    'jquery'
], function ($) {
    'use strict';

    /** The MageDrop split button has no action of its own: clicking it opens the menu */
    return function (config, element) {
        $(element).on('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            $(element).siblings('.action-toggle').trigger('click');
        });
    };
});
