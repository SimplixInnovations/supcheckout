jQuery(function ($) {
    'use strict';

    const optionsData = {
        daily: { '1': 'Every Day' },
        weekly: { '1': 'Every Week', '2': 'Every 2 Weeks', '3': 'Every 3 Weeks' },
        monthly: { '1': 'Every Month', '2': 'Every 2 Months' },
        quarterly: { '1': 'Every Quarter', '2': 'Every 2 Quarters', '3': 'Every 3 Quarters' },
        yearly: { '1': 'Every Year' }
    };

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
            $intervalSelect.append($('<option></option>').val('0').text('One-time'));
            $intervalSelect.val('0');
            intervalRow.hide();
            return;
        }

        intervalRow.show();
        $intervalSelect.append($('<option></option>').val('').text('Select Interval'));
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
