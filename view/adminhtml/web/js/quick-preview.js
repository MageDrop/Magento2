define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'mage/translate',
    'uiRegistry'
], function ($, alert, $t, registry) {
    'use strict';

    return function (config, element) {
        var previewUrl = config.previewUrl;
        var formName = config.formName;
        var entityType = config.entityType;
        var entityId = config.entityId;
        var entityIdKey = config.entityIdKey;

        $(element).on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            var form = registry.get(formName);

            if (!form || !form.source) {
                alert({content: $t('Could not access form data.')});
                return;
            }

            var data = form.source.get('data');
            var formData = extractFormData(data);

            if (!Object.keys(formData).length) {
                alert({content: $t('No form data found.')});
                return;
            }

            var resolvedId = entityId || data[entityIdKey] || '';

            $(element).text($t('Creating preview...')).prop('disabled', true);

            $.ajax({
                url: previewUrl,
                type: 'POST',
                data: {
                    entity_type: entityType,
                    entity_id: resolvedId,
                    form_data: formData,
                    form_key: FORM_KEY
                },
                dataType: 'json',
                success: function (response) {
                    $(element).text($t('Quick Preview')).prop('disabled', false);

                    if (response.success && response.preview_url) {
                        alert({
                            title: $t('Preview Ready'),
                            content: '<p>' + $t('%1 change(s) detected.').replace('%1', response.change_count) + '</p>' +
                                     '<p style="margin-top: 10px;">' +
                                     '<a href="' + response.preview_url + '" target="_blank" id="magedrop-open-preview" ' +
                                     'style="display: inline-block; background: #7c3aed; color: white; padding: 8px 20px; border-radius: 5px; ' +
                                     'text-decoration: none; font-weight: 600; font-size: 14px;">' +
                                     $t('Open Preview') + '</a></p>' +
                                     '<p style="margin-top: 12px;">' +
                                     '<input type="text" id="magedrop-preview-url" value="' + response.preview_url + '" readonly ' +
                                     'style="width: 100%; padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; color: #666; background: #f9f9f9;">' +
                                     '</p>' +
                                     '<p style="margin-top: 6px;">' +
                                     '<button type="button" id="magedrop-copy-url" ' +
                                     'style="background: none; border: none; color: #7c3aed; cursor: pointer; font-size: 12px; padding: 0;">' +
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
                    } else {
                        alert({content: response.message || $t('No changes detected.')});
                    }
                },
                error: function () {
                    $(element).text($t('Quick Preview')).prop('disabled', false);
                    alert({content: $t('Failed to create preview. Check the MageDrop connection.')});
                }
            });
        });

        function extractFormData(data) {
            var entityData = {};

            entityData = data;

            // Filter to scalar values only, skip internals
            var ignored = [
                'form_key', 'entity_id', 'row_id', 'page_id', 'block_id',
                'created_at', 'updated_at', 'created_in', 'updated_in',
                'store_id', 'identifier',
                'layout_update_selected', 'layout_update_xml', 'custom_layout_update_xml',
                'custom_design', 'custom_design_from', 'custom_design_to',
                'custom_theme', 'custom_root_template', 'page_layout'
            ];

            var filtered = {};
            $.each(entityData, function (key, value) {
                if (ignored.indexOf(key) !== -1) return;
                if (key.indexOf('use_config_') === 0 || key.indexOf('use_default_') === 0) return;
                if (typeof value === 'object' && value !== null) return;

                filtered[key] = value;
            });

            return filtered;
        }
    };
});
