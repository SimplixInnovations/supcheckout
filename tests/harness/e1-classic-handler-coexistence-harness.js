'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.resolve(__dirname, '..', '..');
const paymentSource = fs.readFileSync(path.join(ROOT, 'assets', 'js', 'new-upay.js'), 'utf8');
const subscriptionSource = fs.readFileSync(path.join(ROOT, 'assets', 'js', 'subscription-checkout.js'), 'utf8');

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

const state = {
    handlers: [],
    selectedPaymentMethod: 'upayments',
    plan: 'monthly',
    interval: '',
};

const body = {};
const checkbox = { checked: false };
const toast = { textContent: '', classList: { add() {}, remove() {} } };
const document = {
    body,
    getElementById(id) {
        if (id === 'chkSaveCard') return checkbox;
        if (id === 'wc-toast') return toast;
        return null;
    },
};

function removeOwned(target, eventName, selector) {
    state.handlers = state.handlers.filter(function (entry) {
        if (entry.target !== target || entry.eventName !== eventName) return true;
        if (typeof selector === 'string' && entry.selector !== selector) return true;
        return false;
    });
}

function eventCollection(target) {
    return {
        length: 1,
        on(eventName, selectorOrHandler, maybeHandler) {
            const delegated = typeof selectorOrHandler === 'string';
            state.handlers.push({
                target,
                eventName,
                selector: delegated ? selectorOrHandler : null,
                handler: delegated ? maybeHandler : selectorOrHandler,
            });
            return this;
        },
        off(eventName, selector) {
            removeOwned(target, eventName, selector);
            return this;
        },
        trigger() { return this; },
        submit() { return this; },
    };
}

function optionCollection() {
    return {
        value: '',
        label: '',
        val(value) { this.value = String(value); return this; },
        text(value) { this.label = String(value); return this; },
    };
}

function hiddenCollection() {
    return {
        length: 1,
        value: '',
        val(value) {
            if (arguments.length) {
                this.value = String(value);
                return this;
            }
            return this.value;
        },
    };
}

function planCollection() {
    return {
        length: 1,
        val(value) {
            if (arguments.length) {
                state.plan = String(value);
                return this;
            }
            return state.plan;
        },
    };
}

function intervalCollection() {
    return {
        length: 1,
        val(value) {
            if (arguments.length) {
                state.interval = String(value);
                return this;
            }
            return state.interval;
        },
        empty() { state.interval = ''; return this; },
        append(option) {
            if (state.interval === '') state.interval = String(option.value);
            return this;
        },
        closest() { return { show() {}, hide() {} }; },
    };
}

function buttonCollection() {
    return {
        length: 1,
        hide() { return this; },
        show() { return this; },
        prop() { return false; },
        trigger() { return this; },
    };
}

function jquery(value) {
    if (typeof value === 'function') {
        value(jquery);
        return undefined;
    }
    if (value === body) return eventCollection('body');
    if (value === 'form.checkout') return eventCollection('form');
    if (value === 'button#place_order' || value === 'form.checkout button#place_order') return buttonCollection();
    if (value === 'input[name="payment_method"]:checked') {
        return { length: 1, val() { return state.selectedPaymentMethod; } };
    }
    if (value === 'select[name="upay_subscription_plan"]') return planCollection();
    if (value === '#upay_subscription_interval') return intervalCollection();
    if (value === '<option></option>') return optionCollection();
    if (value === '#upayment_payment_type' || value === '#card_token' || value === '#save_card') return hiddenCollection();
    throw new Error('Unexpected jQuery selector: ' + String(value));
}

jquery.each = function each(object, callback) {
    Object.keys(object).forEach(function (key) { callback(key, object[key]); });
};

const window = {
    supCheckout: {},
    setTimeout(fn) { fn(); return 1; },
};
const sandbox = { console, document, window, jQuery: jquery, wcUser: { isLoggedIn: true } };

function run(source, filename) {
    vm.runInNewContext(source, sandbox, { filename });
}

run(paymentSource, 'new-upay.js');
run(subscriptionSource, 'subscription-checkout.js');
state.handlers.push({ target: 'body', eventName: 'updated_checkout.thirdParty', selector: null, handler() {} });
run(subscriptionSource, 'subscription-checkout.js');
run(paymentSource, 'new-upay.js');

const paymentChange = state.handlers.filter(function (entry) {
    return entry.target === 'form'
        && entry.eventName === 'change.supcheckoutPaymentLifecycle'
        && entry.selector === 'input[name="payment_method"]';
});
const subscriptionChange = state.handlers.filter(function (entry) {
    return entry.target === 'body'
        && entry.eventName === 'change.supcheckoutSubscription'
        && entry.selector === 'select[name="upay_subscription_plan"]';
});
const updated = state.handlers.filter(function (entry) {
    return entry.target === 'body' && baseEvent(entry.eventName) === 'updated_checkout';
});

record(paymentChange.length === 1,
    'modern Classic script owns exactly one payment-method lifecycle handler after cross-script re-evaluation');
record(subscriptionChange.length === 1,
    'subscription script owns exactly one plan-change handler after cross-script re-evaluation');
record(updated.filter(entry => entry.eventName === 'updated_checkout.supcheckoutPaymentLifecycle').length === 1,
    'modern updated_checkout namespace survives subscription rebinding exactly once');
record(updated.filter(entry => entry.eventName === 'updated_checkout.supcheckoutSubscription').length === 1,
    'subscription updated_checkout namespace survives modern rebinding exactly once');
record(updated.filter(entry => entry.eventName === 'updated_checkout.thirdParty').length === 1,
    'third-party updated_checkout namespace survives both SUPCheckout scripts');
record(updated.length === 3,
    'combined checkout page retains exactly two first-party and one third-party updated_checkout listeners');

console.log('Classic handler coexistence results: ' + pass + ' passed, ' + fail + ' failed.');
if (fail > 0) process.exitCode = 1;
