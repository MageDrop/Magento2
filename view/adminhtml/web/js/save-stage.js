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
        var stageUrl = config.stageUrl;
        var formName = config.formName;
        var entityIdKey = config.entityIdKey;
        var entityType = config.entityType;
        var entityId = config.entityId;

        $(element).on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            $.ajax({
                url: releasesUrl,
                type: 'GET',
                dataType: 'json',
                beforeSend: function () {
                    $(element).text($t('Loading...')).prop('disabled', true);
                },
                success: function (response) {
                    $(element).text($t('Save & Stage')).prop('disabled', false);

                    var releases = response.releases || [];
                    if (!releases.length) {
                        alert({content: $t('No releases available. Create one in the MageDrop dashboard first.')});
                        return;
                    }

                    showReleaseModal(releases);
                },
                error: function () {
                    $(element).text($t('Save & Stage')).prop('disabled', false);
                    alert({content: $t('Failed to fetch releases.')});
                }
            });
        });

        function showReleaseModal(releases) {
            var optionsHtml = '';
            $.each(releases, function (i, release) {
                optionsHtml += '<option value="' + release.id + '">' + $('<span>').text(release.name).html() + '</option>';
            });

            var html = '<div id="magedrop-save-stage-modal">' +
                '<p style="margin-bottom: 12px;">' + $t('Select a release to stage the current changes to.') + '</p>' +
                '<select id="magedrop-save-stage-select" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">' +
                optionsHtml +
                '</select>' +
                '<p style="margin-top: 12px; color: #666; font-size: 12px;">' +
                $t('This will stage the current form data to the selected release.') +
                '</p>' +
                '</div>';

            var $content = $(html);

            var options = {
                type: 'popup',
                responsive: true,
                title: $t('Save & Stage to Release'),
                buttons: [{
                    text: $t('Save & Stage'),
                    class: 'action-primary',
                    click: function () {
                        var releaseId = $content.find('#magedrop-save-stage-select').val();
                        var modalInstance = this;
                        submitStage(releaseId, modalInstance);
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

        function submitStage(releaseId, modalInstance) {
            var $btn = modalInstance.element.closest('.modal-inner-wrap').find('.action-primary');
            $btn.text($t('Staging...')).prop('disabled', true);

            var form = registry.get(formName);

            if (!form || !form.source) {
                modalInstance.closeModal();
                alert({content: $t('Could not access form data.')});
                return;
            }

            var data = form.source.get('data');
            var formData = extractFormData(data);

            if (!Object.keys(formData).length) {
                modalInstance.closeModal();
                alert({content: $t('No form data found.')});
                return;
            }

            var resolvedId = entityId || data[entityIdKey] || '';

            $.ajax({
                url: stageUrl,
                type: 'POST',
                data: {
                    release_id: releaseId,
                    entity_type: entityType,
                    entity_id: resolvedId,
                    form_data: formData,
                    form_key: FORM_KEY
                },
                dataType: 'json',
                success: function (response) {
                    modalInstance.closeModal();

                    if (!response.success) {
                        alert({content: response.message || $t('Failed to stage changes.')});
                        return;
                    }

                    if (!response.staged) {
                        alert({
                            title: $t('No Changes Detected'),
                            content: $t('The current form data matches what is already live. Nothing to stage.')
                        });
                        return;
                    }

                    alert({
                        title: $t('Changes Staged'),
                        content: $t('%1 change(s) staged to "%2".')
                            .replace('%1', response.change_count)
                            .replace('%2', response.release_name),
                        actions: {
                            always: function () {
                                window.location.href = response.redirect_url;
                            }
                        }
                    });
                },
                error: function () {
                    modalInstance.closeModal();
                    alert({content: $t('Failed to stage changes. Check the MageDrop connection.')});
                }
            });
        }

        function extractFormData(data) {
            var ignored = [
                'form_key', 'entity_id', 'row_id', 'page_id', 'block_id',
                'created_at', 'updated_at', 'created_in', 'updated_in',
                'store_id', 'identifier',
                'layout_update_selected', 'layout_update_xml', 'custom_layout_update_xml',
                'custom_design', 'custom_design_from', 'custom_design_to',
                'custom_theme', 'custom_root_template', 'page_layout'
            ];

            var filtered = {};
            $.each(data, function (key, value) {
                if (ignored.indexOf(key) !== -1) return;
                if (key.indexOf('use_config_') === 0 || key.indexOf('use_default_') === 0) return;
                if (typeof value === 'object' && value !== null) return;

                filtered[key] = value;
            });

            return filtered;
        }
    };
});
