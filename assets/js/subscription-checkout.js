jQuery(function ($) {
    'use strict';

    const i18n = (
        typeof wcUser === 'object'
        && wcUser !== null
        && wcUser.i18n
        && typeof wcUser.i18n === 'object'
    ) ? wcUser.i18n : {};
    const optionsData = (
        i18n.intervalLabels
        && typeof i18n.intervalLabels === 'object'
    ) ? i18n.intervalLabels : {};

    function toggleIntervalField(preserveSelection) {
        const $planSelect = $('select[name="upay_subscription_plan"]');
        const $intervalSelect = $('#upay_subscription_interval');
        if (!$planSelect.length || !$intervalSelect.length) {
            return;
        }

        let selectedPlan = $planSelect.val();
        const selectedInterval = preserveSelection === true ? String($intervalSelect.val() || '') : '';
        const intervalRow = $intervalSelect.closest('.form-row');
        const isLoggedIn = typeof wcUser === 'object' && wcUser !== null && !!wcUser.isLoggedIn;

        if (!isLoggedIn && selectedPlan !== 'one_time') {
            $planSelect.val('one_time');
            selectedPlan = 'one_time';
        }

        $intervalSelect.empty();

        if (selectedPlan === 'one_time' || !optionsData[selectedPlan]) {
            $intervalSelect.append($('<option></option>').val('0').text(typeof i18n.oneTime === 'string' ? i18n.oneTime : ''));
            $intervalSelect.val('0');
            intervalRow.hide();
            return;
        }

        intervalRow.show();
        $intervalSelect.append($('<option></option>').val('').text(typeof i18n.selectInterval === 'string' ? i18n.selectInterval : ''));
        $.each(optionsData[selectedPlan], function (value, label) {
            $intervalSelect.append($('<option></option>').val(value).text(label));
        });

        if (Object.prototype.hasOwnProperty.call(optionsData[selectedPlan], selectedInterval)) {
            $intervalSelect.val(selectedInterval);
        }
    }

    toggleIntervalField(false);
    const $body = $(document.body);
    $body
        .off('change.supcheckoutSubscription', 'select[name="upay_subscription_plan"]')
        .on('change.supcheckoutSubscription', 'select[name="upay_subscription_plan"]', function () {
            toggleIntervalField(false);
        });
    $body
        .off('updated_checkout.supcheckoutSubscription')
        .on('updated_checkout.supcheckoutSubscription', function () {
            toggleIntervalField(true);
        });
});
