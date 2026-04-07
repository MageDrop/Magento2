define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'Magento_Ui/js/modal/modal',
    'mage/translate',
    'uiRegistry'
], function ($, alert, modal, $t, registry) {
    'use strict';

    return function (config, element) {
        var releasesUrl = config.releasesUrl;
        var checkUrl = config.checkUrl;
        var entityType = config.entityType;
        var entityId = config.entityId;
        var entityIdKey = config.entityIdKey;
        var formName = config.formName;

        $(element).on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            var resolvedId = entityId;
            if (!resolvedId) {
                var form = registry.get(formName);
                if (form && form.source) {
                    resolvedId = form.source.get('data')[entityIdKey] || '';
                }
            }

            if (!resolvedId) {
                alert({content: $t('Please save the entity first before loading changes.')});
                return;
            }

            $.ajax({
                url: releasesUrl,
                type: 'GET',
                dataType: 'json',
                beforeSend: function () {
                    $(element).text($t('Loading...')).prop('disabled', true);
                },
                success: function (response) {
                    $(element).text($t('Load from Release')).prop('disabled', false);

                    var releases = response.releases || [];
                    if (!releases.length) {
                        alert({content: $t('No releases available.')});
                        return;
                    }

                    showReleaseModal(releases, resolvedId);
                },
                error: function () {
                    $(element).text($t('Load from Release')).prop('disabled', false);
                    alert({content: $t('Failed to fetch releases.')});
                }
            });
        });

        function showReleaseModal(releases, resolvedId) {
            var optionsHtml = '';
            $.each(releases, function (i, release) {
                optionsHtml += '<option value="' + release.id + '">' + $('<span>').text(release.name).html() + '</option>';
            });

            var html = '<div id="magedrop-load-modal">' +
                '<p style="margin-bottom: 12px;">' + $t('Select a release to load its staged changes into the current form.') + '</p>' +
                '<select id="magedrop-load-release-select" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">' +
                optionsHtml +
                '</select>' +
                '<p style="margin-top: 12px; color: #666; font-size: 12px;">' +
                $t('This will reload the page with the staged values applied. Your unsaved changes will be lost.') +
                '</p>' +
                '</div>';

            var $content = $(html);

            var options = {
                type: 'popup',
                responsive: true,
                title: $t('Load Changes from Release'),
                buttons: [{
                    text: $t('Load Changes'),
                    class: 'action-primary',
                    click: function () {
                        var releaseId = $content.find('#magedrop-load-release-select').val();
                        checkAndRedirect(releaseId, resolvedId, this);
                    }
                }, {
                    text: $t('Cancel'),
                    class: 'action-secondary',
                    click: function () {
                        this.closeModal();
                    }
                }]
            };

            modal(options, $content);
            $content.modal('openModal');
        }

        function checkAndRedirect(releaseId, resolvedId, modalInstance) {
            var $btn = modalInstance.element.closest('.modal-inner-wrap').find('.action-primary');
            $btn.text($t('Loading...')).prop('disabled', true);

            $.ajax({
                url: checkUrl,
                type: 'POST',
                data: {
                    release_id: releaseId,
                    entity_type: entityType,
                    entity_id: resolvedId,
                    form_key: FORM_KEY
                },
                dataType: 'json',
                success: function (response) {
                    if (!response.success) {
                        modalInstance.closeModal();
                        alert({content: response.message || $t('No changes found.')});
                        return;
                    }

                    window.location.href = response.redirect_url;
                },
                error: function () {
                    modalInstance.closeModal();
                    alert({content: $t('Failed to check for changes.')});
                }
            });
        }
    };
});
