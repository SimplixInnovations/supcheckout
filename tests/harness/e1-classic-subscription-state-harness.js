'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.resolve(__dirname, '..', '..');
const SOURCE = path.join(ROOT, 'assets', 'js', 'subscription-checkout.js');
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

function createScene(loggedIn) {
    const state = {
        plan: 'monthly',
        interval: '',
        options: [],
        intervalVisible: true,
        handlers: [],
    };

    function createOption() {
        return {
            value: '',
            label: '',
            val(value) {
                this.value = String(value);
                return this;
            },
            text(value) {
                this.label = String(value);
                return this;
            },
        };
    }

    function planCollection() {
        return {
            length: 1,
            val(value) {
                if (arguments.length > 0) {
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
                if (arguments.length > 0) {
                    state.interval = String(value);
                    return this;
                }
                return state.interval;
            },
            empty() {
                state.options = [];
                state.interval = '';
                return this;
            },
            append(option) {
                state.options.push({ value: option.value, label: option.label });
                if (state.options.length === 1) {
                    state.interval = option.value;
                }
                return this;
            },
            closest() {
                return {
                    show() {
                        state.intervalVisible = true;
                    },
                    hide() {
                        state.intervalVisible = false;
                    },
                };
            },
        };
    }

    function bodyCollection() {
        return {
            on(eventName, selectorOrHandler, maybeHandler) {
                const delegated = typeof selectorOrHandler === 'string';
                state.handlers.push({
                    eventName,
                    selector: delegated ? selectorOrHandler : null,
                    handler: delegated ? maybeHandler : selectorOrHandler,
                });
                return this;
            },
            off(eventName, selector) {
                state.handlers = state.handlers.filter(function (entry) {
                    if (entry.eventName !== eventName) {
                        return true;
                    }
                    if (typeof selector === 'string' && entry.selector !== selector) {
                        return true;
                    }
                    return false;
                });
                return this;
            },
        };
    }

    const sandbox = {
        console,
        document: { body: {} },
        wcUser: { isLoggedIn: !!loggedIn },
    };

    function jquery(value) {
        if (typeof value === 'function') {
            value(jquery);
            return undefined;
        }
        if (value === sandbox.document.body) {
            return bodyCollection();
        }
        if (value === 'select[name="upay_subscription_plan"]') {
            return planCollection();
        }
        if (value === '#upay_subscription_interval') {
            return intervalCollection();
        }
        if (value === '<option></option>') {
            return createOption();
        }
        throw new Error('Unexpected jQuery selector in harness: ' + String(value));
    }

    jquery.each = function each(object, callback) {
        Object.keys(object).forEach(function eachKey(key) {
            callback(key, object[key]);
        });
    };

    sandbox.jQuery = jquery;

    function evaluateSource() {
        vm.runInNewContext(source, sandbox, { filename: SOURCE });
    }

    evaluateSource();

    return {
        state,
        rerun: evaluateSource,
        trigger(eventName) {
            const matching = state.handlers.filter(function (entry) {
                return baseEvent(entry.eventName) === eventName;
            });
            if (matching.length === 0) {
                throw new Error('No handler registered for ' + eventName);
            }
            matching.forEach(function (entry) {
                entry.handler();
            });
        },
        addThirdPartyHandler(eventName, selector) {
            state.handlers.push({ eventName, selector: selector || null, handler() {} });
        },
        countHandlers(eventName, selector) {
            return state.handlers.filter(function (entry) {
                return baseEvent(entry.eventName) === eventName
                    && (typeof selector !== 'string' || entry.selector === selector);
            }).length;
        },
        countExactHandlers(eventName) {
            return state.handlers.filter(function (entry) {
                return entry.eventName === eventName;
            }).length;
        },
    };
}

console.log('Running e1-classic-subscription-state-harness.js');

{
    const scene = createScene(true);
    scene.state.interval = '2';
    scene.trigger('updated_checkout');
    record(
        scene.state.interval === '2',
        'valid selected monthly interval survives updated_checkout'
    );
}

{
    const scene = createScene(true);
    scene.state.interval = '9';
    scene.trigger('updated_checkout');
    record(
        scene.state.interval === '',
        'invalid interval is reset after updated_checkout'
    );
}

{
    const scene = createScene(true);
    scene.state.plan = 'one_time';
    scene.state.interval = '2';
    scene.trigger('updated_checkout');
    record(
        scene.state.interval === '0' && scene.state.intervalVisible === false,
        'one-time checkout forces interval 0 and hides interval row'
    );
}

{
    const scene = createScene(false);
    record(
        scene.state.plan === 'one_time'
            && scene.state.interval === '0'
            && scene.state.intervalVisible === false,
        'logged-out subscription selection is coerced to one-time'
    );
}

{
    const scene = createScene(true);
    scene.state.plan = 'weekly';
    scene.state.interval = '3';
    scene.trigger('updated_checkout');
    record(
        scene.state.interval === '3',
        'valid selected weekly interval survives updated_checkout'
    );
}

{
    const scene = createScene(true);
    scene.state.interval = '2';
    scene.state.plan = 'weekly';
    scene.trigger('change');
    record(
        scene.state.interval === '',
        'deliberate plan change resets an inherited interval even when numeric value remains valid'
    );
}

{
    const scene = createScene(true);
    scene.addThirdPartyHandler('updated_checkout.thirdParty');
    scene.rerun();
    record(
        scene.countHandlers('change', 'select[name="upay_subscription_plan"]') === 1,
        'repeated subscription script evaluation owns exactly one plan-change handler'
    );
    record(
        scene.countHandlers('updated_checkout') === 2,
        'repeated subscription script evaluation preserves one first-party and one third-party updated_checkout handler'
    );
    record(
        scene.countExactHandlers('updated_checkout.thirdParty') === 1,
        'subscription handler refresh preserves unrelated namespaced updated_checkout listeners'
    );
}

console.log('Classic subscription state results: ' + pass + ' passed, ' + fail + ' failed.');
if (fail > 0) {
    process.exitCode = 1;
}
