'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.resolve(__dirname, '..', '..');
const SOURCE = path.join(ROOT, 'assets', 'js', 'new-upay.js');
const source = fs.readFileSync(SOURCE, 'utf8');

let pass = 0;
let fail = 0;

function record(condition, description) {
    if (condition) {
        pass++;
        console.log('PASS: ' + description);
    } else {
        fail++;
        console.log('FAIL: ' + description);
    }
}

function baseEvent(eventName) {
    return String(eventName).split('.')[0];
}

function createScene(options) {
    options = options || {};

    const state = {
        selectedPaymentMethod: options.selectedPaymentMethod || 'upayments',
        paymentType: options.paymentType || '',
        cardToken: options.cardToken || 'stale-card',
        saveCard: options.saveCard || '1',
        checkboxChecked: options.checkboxChecked !== false,
        placeOrderExists: options.placeOrderExists !== false,
        placeOrderDisabled: options.placeOrderDisabled === true,
        placeOrderHidden: false,
        methodButtons: options.methodButtons === undefined ? 1 : options.methodButtons,
        placeOrderClicks: 0,
        directFormSubmits: 0,
        formSubmitEvents: 0,
        handlers: [],
    };

    const checkbox = {
        get checked() {
            return state.checkboxChecked;
        },
        set checked(value) {
            state.checkboxChecked = !!value;
        },
    };

    const toast = {
        textContent: '',
        classList: { add() {}, remove() {} },
    };

    const document = {
        body: {},
        getElementById(id) {
            if (id === 'chkSaveCard') return checkbox;
            if (id === 'wc-toast') return toast;
            return null;
        },
    };

    function removeOwnedHandlers(target, eventName, selector) {
        state.handlers = state.handlers.filter(function (entry) {
            if (entry.target !== target || entry.eventName !== eventName) {
                return true;
            }
            if (typeof selector === 'string' && entry.selector !== selector) {
                return true;
            }
            return false;
        });
    }

    function hiddenCollection(key) {
        return {
            length: 1,
            val(value) {
                if (arguments.length > 0) {
                    state[key] = String(value);
                    return this;
                }
                return state[key];
            },
        };
    }

    function placeOrderCollection() {
        return {
            length: state.placeOrderExists ? 1 : 0,
            hide() {
                if (state.placeOrderExists) state.placeOrderHidden = true;
                return this;
            },
            show() {
                if (state.placeOrderExists) state.placeOrderHidden = false;
                return this;
            },
            prop(name) {
                if (name === 'disabled') return state.placeOrderDisabled;
                return undefined;
            },
            trigger(eventName) {
                if (eventName === 'click' && state.placeOrderExists && !state.placeOrderDisabled) {
                    state.placeOrderClicks++;
                }
                return this;
            },
        };
    }

    function checkoutFormCollection() {
        return {
            length: 1,
            submit() {
                state.directFormSubmits++;
                return this;
            },
            trigger(eventName) {
                if (eventName === 'submit') state.formSubmitEvents++;
                return this;
            },
            on(eventName, selectorOrHandler, maybeHandler) {
                const delegated = typeof selectorOrHandler === 'string';
                state.handlers.push({
                    target: 'form',
                    eventName,
                    selector: delegated ? selectorOrHandler : null,
                    handler: delegated ? maybeHandler : selectorOrHandler,
                });
                return this;
            },
            off(eventName, selector) {
                removeOwnedHandlers('form', eventName, selector);
                return this;
            },
        };
    }

    function bodyCollection() {
        return {
            on(eventName, selectorOrHandler, maybeHandler) {
                const delegated = typeof selectorOrHandler === 'string';
                state.handlers.push({
                    target: 'body',
                    eventName,
                    selector: delegated ? selectorOrHandler : null,
                    handler: delegated ? maybeHandler : selectorOrHandler,
                });
                return this;
            },
            off(eventName, selector) {
                removeOwnedHandlers('body', eventName, selector);
                return this;
            },
        };
    }

    function jquery(value) {
        if (typeof value === 'function') {
            value();
            return undefined;
        }
        if (value === document.body) return bodyCollection();
        if (value === '#upayment_payment_type') return hiddenCollection('paymentType');
        if (value === '#card_token') return hiddenCollection('cardToken');
        if (value === '#save_card') return hiddenCollection('saveCard');
        if (value === 'input[name="payment_method"]:checked') {
            return { length: 1, val() { return state.selectedPaymentMethod; } };
        }
        if (value === 'button#place_order' || value === 'form.checkout button#place_order') {
            return placeOrderCollection();
        }
        if (value === 'form.checkout') return checkoutFormCollection();
        if (value === '.payment_box.payment_method_upayments .upay-payment-method') {
            return { length: state.methodButtons };
        }
        throw new Error('Unexpected jQuery selector in harness: ' + String(value));
    }

    const window = {
        supCheckout: {},
        setTimeout(fn) { fn(); return 1; },
    };

    const sandbox = { console, document, window, jQuery: jquery };
    function evaluateSource() {
        vm.runInNewContext(source, sandbox, { filename: SOURCE });
    }
    evaluateSource();

    return {
        state,
        api: window.supCheckout,
        rerun: evaluateSource,
        addThirdPartyHandler(target, eventName, selector) {
            state.handlers.push({ target, eventName, selector: selector || null, handler() {} });
        },
        countHandlers(target, eventName, selector) {
            return state.handlers.filter(function (entry) {
                return entry.target === target
                    && baseEvent(entry.eventName) === eventName
                    && (typeof selector !== 'string' || entry.selector === selector);
            }).length;
        },
        countExactHandlers(target, eventName) {
            return state.handlers.filter(function (entry) {
                return entry.target === target && entry.eventName === eventName;
            }).length;
        },
    };
}

console.log('Running e1-classic-place-order-lifecycle-harness.js');

{
    const scene = createScene();
    scene.api.submitPaymentMethod('knet');
    record(scene.state.paymentType === 'knet', 'payment source is written before checkout submission');
    record(scene.state.cardToken === '', 'new payment source clears stale saved-card selection');
    record(scene.state.saveCard === '0' && scene.state.checkboxChecked === false,
        'non-CC source clears save-card consent state');
    record(scene.state.placeOrderClicks === 1,
        'regular payment source delegates through canonical place-order click');
    record(scene.state.directFormSubmits === 0,
        'regular payment source never calls direct form.submit() when place-order exists');
}

{
    const scene = createScene();
    scene.api.submitSavedCard({ value: 'sc1_' + 'a'.repeat(64) });
    record(scene.state.paymentType === 'cc', 'saved-card source selects credit-card payment type');
    record(scene.state.cardToken === 'sc1_' + 'a'.repeat(64), 'saved-card opaque selection is written before submission');
    record(scene.state.saveCard === '0' && scene.state.checkboxChecked === false,
        'saved-card source clears new-card save consent');
    record(scene.state.placeOrderClicks === 1,
        'saved-card source delegates through canonical place-order click');
    record(scene.state.directFormSubmits === 0,
        'saved-card source never calls direct form.submit() when place-order exists');
}

{
    const scene = createScene({ placeOrderDisabled: true });
    scene.api.submitPaymentMethod('knet');
    record(scene.state.placeOrderClicks === 0,
        'disabled canonical place-order button is respected');
    record(scene.state.directFormSubmits === 0 && scene.state.formSubmitEvents === 0,
        'disabled place-order state cannot be bypassed by a form-submit fallback');
}

{
    const scene = createScene({ placeOrderExists: false });
    scene.api.submitPaymentMethod('knet');
    record(scene.state.formSubmitEvents === 1,
        'missing canonical place-order control falls back to the checkout form submit event');
    record(scene.state.directFormSubmits === 0,
        'fallback uses submit event rather than direct jQuery form.submit() shortcut');
}

{
    const scene = createScene({ selectedPaymentMethod: 'upayments' });
    record(scene.state.placeOrderHidden === true,
        'UPayments selection hides but does not disable the canonical place-order control');
}

{
    const scene = createScene({ selectedPaymentMethod: 'cod' });
    record(scene.state.placeOrderHidden === false,
        'non-UPayments selection leaves the canonical place-order control visible');
}

{
    const scene = createScene();
    scene.addThirdPartyHandler('body', 'updated_checkout.thirdParty');
    scene.rerun();
    record(
        scene.countHandlers('body', 'change', 'input[name="payment_method"]') === 1,
        'repeated modern checkout script evaluation owns exactly one payment-method change handler'
    );
    record(
        scene.countHandlers('body', 'updated_checkout') === 2,
        'repeated modern checkout script evaluation preserves one first-party and one third-party updated_checkout handler'
    );
    record(
        scene.countExactHandlers('body', 'updated_checkout.thirdParty') === 1,
        'modern checkout handler refresh preserves unrelated namespaced updated_checkout listeners'
    );
}

{
    // Methods could not be loaded: no UPayments button renders, so hiding the
    // canonical button would leave the customer with no way to pay.
    const scene = createScene({ selectedPaymentMethod: 'upayments', methodButtons: 0 });
    record(scene.state.placeOrderHidden === false,
        'UPayments selected with no method buttons keeps the canonical place-order control visible');
}

{
    // Pay-for-order (form#order_review) has no form.checkout: the gateway switch
    // must still re-show place-order.
    const scene = createScene({ selectedPaymentMethod: 'upayments' });
    record(scene.state.placeOrderHidden === true, 'pay-for-order starts with UPayments selected');
    scene.state.selectedPaymentMethod = 'cod';
    const delegated = scene.state.handlers.filter(function (entry) {
        return entry.target === 'body' && baseEvent(entry.eventName) === 'change' && entry.selector === 'input[name="payment_method"]';
    });
    delegated.forEach(function (entry) { entry.handler(); });
    record(delegated.length === 1 && scene.state.placeOrderHidden === false,
        'a gateway switch anywhere on the page (incl. pay-for-order) re-shows the place-order control');
}

{
    // The visible save-card checkbox is the customer's consent even if the toggle
    // click happened before this script loaded.
    const scene = createScene({ saveCard: '0', checkboxChecked: true });
    scene.api.submitPaymentMethod('cc');
    record(scene.state.saveCard === '1', 'new-card submission sends the visible save-card consent');
    const unchecked = createScene({ saveCard: '1', checkboxChecked: false });
    unchecked.api.submitPaymentMethod('cc');
    record(unchecked.state.saveCard === '0', 'an unchecked save-card box never sends consent');
}

console.log('Classic place-order lifecycle results: ' + pass + ' passed, ' + fail + ' failed.');
if (fail > 0) process.exitCode = 1;
