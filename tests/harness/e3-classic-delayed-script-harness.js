'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.resolve(__dirname, '..', '..');
const SCRIPT_PATH = path.join(ROOT, 'assets', 'js', 'new-upay.js');
const TEMPLATE_PATH = path.join(ROOT, 'templates', 'new-design-form.php');
const scriptSource = fs.readFileSync(SCRIPT_PATH, 'utf8');
const templateSource = fs.readFileSync(TEMPLATE_PATH, 'utf8');

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

function createScene(pendingAction) {
    const state = {
        selectedPaymentMethod: 'upayments',
        paymentType: '',
        cardToken: 'stale-card',
        saveCard: '1',
        checkboxChecked: true,
        placeOrderClicks: 0,
        formSubmitEvents: 0,
        placeOrderVisible: true,
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
            if (entry.target !== target || entry.eventName !== eventName) return true;
            if (typeof selector === 'string' && entry.selector !== selector) return true;
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
            length: 1,
            hide() {
                state.placeOrderVisible = false;
                return this;
            },
            show() {
                state.placeOrderVisible = true;
                return this;
            },
            prop(name) {
                if (name === 'disabled') return false;
                return undefined;
            },
            trigger(eventName) {
                if (eventName === 'click') state.placeOrderClicks++;
                return this;
            },
        };
    }

    function checkoutFormCollection() {
        return {
            length: 1,
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
        throw new Error('Unexpected jQuery selector in delayed-script harness: ' + String(value));
    }

    const window = {
        supcheckoutPendingAction: pendingAction,
        setTimeout(fn) { fn(); return 1; },
    };

    const sandbox = { console, document, window, jQuery: jquery };

    function evaluateSource() {
        vm.runInNewContext(scriptSource, sandbox, { filename: SCRIPT_PATH });
    }

    function dispatch(target, eventName) {
        state.handlers
            .filter(function (entry) {
                return entry.target === target && entry.eventName === eventName;
            })
            .slice()
            .forEach(function (entry) {
                entry.handler.call(null, { type: eventName });
            });
    }

    function countHandlers(target, eventName, selector) {
        return state.handlers.filter(function (entry) {
            if (entry.target !== target || entry.eventName !== eventName) return false;
            if (typeof selector === 'string' && entry.selector !== selector) return false;
            return true;
        }).length;
    }

    evaluateSource();

    return {
        state,
        window,
        rerun: evaluateSource,
        dispatch,
        countHandlers,
    };
}

console.log('Running e3-classic-delayed-script-harness.js');

{
    const scene = createScene({ type: 'payment_method', value: 'knet' });
    record(scene.state.paymentType === 'knet',
        'a payment click queued before new-upay.js loads is replayed after script initialization');
    record(scene.state.cardToken === '',
        'replayed delayed payment action clears stale saved-card identity');
    record(scene.state.saveCard === '0' && scene.state.checkboxChecked === false,
        'replayed delayed non-card payment action clears save-card consent');
    record(scene.state.placeOrderClicks === 1,
        'replayed delayed payment action delegates through canonical place-order exactly once');
    record(scene.window.supcheckoutPendingAction == null,
        'consumed delayed payment action is cleared');

    scene.rerun();
    record(scene.state.placeOrderClicks === 1,
        're-evaluating new-upay.js cannot replay a consumed delayed payment action');
}

{
    const token = 'sc1_' + 'a'.repeat(64);
    const scene = createScene({ type: 'saved_card', value: token });
    record(scene.state.paymentType === 'cc',
        'a saved-card click queued before new-upay.js loads selects the CC source');
    record(scene.state.cardToken === token,
        'a queued saved-card action preserves the exact opaque saved-card selection');
    record(scene.state.saveCard === '0' && scene.state.checkboxChecked === false,
        'replayed saved-card action clears new-card save consent');
    record(scene.state.placeOrderClicks === 1,
        'replayed saved-card action delegates through canonical place-order exactly once');
    record(scene.window.supcheckoutPendingAction == null,
        'consumed delayed saved-card action is cleared');
}

{
    const scene = createScene({ type: 'toggle_save_card', value: false });
    record(scene.state.saveCard === '0',
        'save-card consent interaction queued before script load is synchronized after initialization');
    record(scene.window.supcheckoutPendingAction == null,
        'consumed delayed save-card toggle is cleared');
    record(scene.state.placeOrderClicks === 0,
        'save-card consent replay never submits checkout');
}

{
    const scene = createScene(null);
    const changeEvent = 'change.supcheckoutPaymentLifecycle';
    const updateEvent = 'updated_checkout.supcheckoutPaymentLifecycle';
    const selector = 'input[name="payment_method"]';

    record(scene.countHandlers('form', changeEvent, selector) === 1,
        'script initialization owns exactly one namespaced payment-method lifecycle handler');
    record(scene.countHandlers('body', updateEvent) === 1,
        'script initialization owns exactly one namespaced updated-checkout lifecycle handler');
    record(scene.state.placeOrderVisible === false,
        'initial lifecycle synchronization hides Woo place-order while SUPCheckout is selected');

    scene.rerun();
    scene.rerun();
    record(scene.countHandlers('form', changeEvent, selector) === 1,
        'duplicate or combined script evaluation replaces rather than stacks the payment-method lifecycle handler');
    record(scene.countHandlers('body', updateEvent) === 1,
        'duplicate or combined script evaluation replaces rather than stacks the updated-checkout lifecycle handler');

    scene.state.selectedPaymentMethod = 'cod';
    scene.dispatch('body', updateEvent);
    record(scene.state.placeOrderVisible === true,
        'an updated-checkout fragment cycle restores Woo place-order for another gateway');

    scene.state.selectedPaymentMethod = 'upayments';
    scene.dispatch('body', updateEvent);
    record(scene.state.placeOrderVisible === false,
        'a later updated-checkout fragment cycle re-hides Woo place-order for SUPCheckout');

    for (let cycle = 0; cycle < 5; cycle++) {
        scene.state.selectedPaymentMethod = cycle % 2 === 0 ? 'cod' : 'upayments';
        scene.dispatch('body', updateEvent);
    }
    record(scene.countHandlers('body', updateEvent) === 1,
        'repeated checkout fragment refreshes never multiply SUPCheckout lifecycle ownership');
    record(scene.state.placeOrderClicks === 0 && scene.state.formSubmitEvents === 0,
        'fragment lifecycle synchronization never submits checkout on its own');

    scene.state.selectedPaymentMethod = 'cod';
    scene.dispatch('form', changeEvent);
    record(scene.state.placeOrderVisible === true,
        'payment-method change lifecycle stays functional after repeated fragment refreshes');
}

record(!/onclick\s*=\s*["']\s*supCheckout\./.test(templateSource),
    'modern checkout template has no unconditional inline dependency on supCheckout existing before first interaction');

const guardedSavedCardHandlers = templateSource.match(
    /onclick="if\(window\.supCheckout&amp;&amp;typeof window\.supCheckout\.submitSavedCard==='function'\)\{window\.supCheckout\.submitSavedCard\(this\);\}else\{window\.supcheckoutPendingAction=\{type:'saved_card',value:this\.value\};\}"/g
) || [];
record(guardedSavedCardHandlers.length === 1,
    'modern checkout template has exactly one guarded saved-card handler with canonical dispatch and queued fallback');

console.log('E3 delayed-script results: ' + pass + ' passed, ' + fail + ' failed.');
if (fail > 0) process.exitCode = 1;
