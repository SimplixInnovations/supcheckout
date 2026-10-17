(function ($, window, document) {
    'use strict';

    const api = window.supCheckout = window.supCheckout || {};

    // The gateway id WooCommerce renders the radio and payment_method_{id} box with: the
    // localized current id after the takeover cutover, the legacy id before it.
    function currentGatewayId() {
        const localized = window.supCheckoutI18n;
        return localized && typeof localized.gatewayId === 'string' && localized.gatewayId !== ''
            ? localized.gatewayId
            : 'upayments';
    }

    function syncPlaceOrderButton() {
        const gatewayId = currentGatewayId();
        const selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
        // Hide the canonical button only when a UPayments method button can take its
        // place; with no methods rendered the customer must still be able to submit.
        const hasMethodButtons = $('.payment_box.payment_method_' + gatewayId + ' .upay-payment-method').length > 0;
        if (selectedPaymentMethod === gatewayId && hasMethodButtons) {
            $('button#place_order').hide();
        } else {
            $('button#place_order').show();
        }
    }

    function submitThroughWooCheckout() {
        const placeOrder = $('button#place_order');

        if (placeOrder.length) {
            if (placeOrder.prop('disabled')) {
                return false;
            }

            placeOrder.trigger('click');
            return true;
        }

        $('form.checkout').trigger('submit');
        return true;
    }

    api.submitPaymentMethod = function (buttonValue) {
        $('#upayment_payment_type').val(buttonValue);
        $('#card_token').val('');
        if (buttonValue === 'cc') {
            // The visible checkbox is the consent, even if it was toggled before this
            // script loaded (the inline fallback keeps only the last queued action).
            const consent = document.getElementById('chkSaveCard');
            $('#save_card').val(consent && consent.checked ? '1' : '0');
        } else {
            $('#save_card').val('0');
            const checkbox = document.getElementById('chkSaveCard');
            if (checkbox) {
                checkbox.checked = false;
            }
        }
        submitThroughWooCheckout();
    };

    api.submitSavedCard = function (button) {
        $('#upayment_payment_type').val('cc');
        $('#card_token').val(button.value);
        $('#save_card').val('0');
        const checkbox = document.getElementById('chkSaveCard');
        if (checkbox) {
            checkbox.checked = false;
        }
        submitThroughWooCheckout();
    };

    api.toggleSaveCard = function (loggedUser) {
        const checkbox = document.getElementById('chkSaveCard');
        const saveCardInput = $('#save_card');

        if (loggedUser === false || !checkbox) {
            if (checkbox) {
                checkbox.checked = false;
            }
            saveCardInput.val('0');
            if (loggedUser === false) {
                if (
                    window.supCheckoutI18n
                    && typeof window.supCheckoutI18n.loginRequired === 'string'
                    && window.supCheckoutI18n.loginRequired !== ''
                ) {
                    api.showToast(window.supCheckoutI18n.loginRequired, 3000);
                }
            }
            return;
        }

        saveCardInput.val(checkbox.checked ? '1' : '0');
    };

    api.showToast = function (message, duration) {
        const toast = document.getElementById('wc-toast');
        if (!toast) {
            return;
        }

        toast.textContent = String(message);
        toast.classList.add('show');

        window.setTimeout(function () {
            toast.classList.remove('show');
        }, typeof duration === 'number' ? duration : 3000);
    };

    function consumePendingAction() {
        const pending = window.supcheckoutPendingAction;
        if (!pending || typeof pending !== 'object') {
            return;
        }

        // Clear before dispatch so a repeated script evaluation, checkout
        // fragment refresh, or re-entrant event cannot submit the same delayed
        // first interaction twice.
        window.supcheckoutPendingAction = null;

        if (pending.type === 'payment_method' && typeof pending.value === 'string') {
            api.submitPaymentMethod(pending.value);
            return;
        }

        if (pending.type === 'saved_card' && typeof pending.value === 'string') {
            api.submitSavedCard({ value: pending.value });
            return;
        }

        if (pending.type === 'toggle_save_card') {
            const checkbox = document.getElementById('chkSaveCard');
            if (checkbox && typeof pending.value === 'boolean') {
                checkbox.checked = pending.value;
            }
            api.toggleSaveCard(pending.loggedUser !== false);
        }
    }

    $(function () {
        // Delegated from the body so it works on checkout and on pay-for-order
        // (form#order_review), which never fires updated_checkout.
        $(document.body)
            .off('change.supcheckoutPaymentLifecycle', 'input[name="payment_method"]')
            .on('change.supcheckoutPaymentLifecycle', 'input[name="payment_method"]', syncPlaceOrderButton);
        $(document.body)
            .off('updated_checkout.supcheckoutPaymentLifecycle')
            .on('updated_checkout.supcheckoutPaymentLifecycle', syncPlaceOrderButton);
        syncPlaceOrderButton();
        consumePendingAction();
    });
})(jQuery, window, document);
