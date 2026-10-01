define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'Magento_Ui/js/modal/modal',
    'mage/translate',
    'uiRegistry'
], function ($, alert, modal, $t, registry) {
    'use strict';

    /**
     * Save & Stage: pick a release, then submit the real admin form with the
     * MageDrop flags. The Save controller plugin intercepts the POST, diffs it
     * against the live entity and stages the delta instead of saving.
     */
    return function (config, element) {
        var releasesUrl = config.releasesUrl;
        var formName = config.formName;

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
                $t('Only the fields that differ from the live version are staged. Nothing is saved to the live store.') +
                '</p>' +
                '</div>';

            var $content = $(html);

            modal({
                type: 'popup',
                responsive: true,
                title: $t('Save & Stage to Release'),
                buttons: [{
                    text: $t('Save & Stage'),
                    class: 'action-primary',
                    click: function () {
                        var releaseId = $content.find('#magedrop-save-stage-select').val();
                        this.closeModal();
                        submitStage(releaseId);
                    }
                }, {
                    text: $t('Cancel'),
                    class: 'action-secondary',
                    click: function () {
                        this.closeModal();
                    }
                }]
            }, $content);

            $content.modal('openModal');
        }

        function submitStage(releaseId) {
            var form = registry.get(formName);

            if (!form || typeof form.save !== 'function') {
                alert({content: $t('Could not access the form.')});
                return;
            }

            // Same mechanism core uses for "Save & Continue" (back=edit): extra
            // top-level POST params ride along with the full form submission.
            form.save(undefined, {
                magedrop_stage: 1,
                magedrop_release_id: releaseId
            });
        }
    };
});
