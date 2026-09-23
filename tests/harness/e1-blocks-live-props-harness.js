'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.resolve(__dirname, '..', '..');
const SOURCE = path.join(ROOT, 'assets', 'js', 'upayments-block.js');
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

function containsClass(node, className) {
    if (node === null || node === undefined || node === false) return false;
    if (Array.isArray(node)) return node.some(child => containsClass(child, className));
    if (typeof node !== 'object') return false;
    if (node.props && node.props.className === className) return true;
    return containsClass(node.children || [], className);
}

function createScene(overrides) {
    const settings = Object.assign({
        availability_valid: true,
        is_whitelabled: true,
        payment_icons: { cc: 'Credit Card' },
        saved_cards: [],
        is_logged_in: true,
        save_card_enabled: true,
        is_subscription_enabled: true,
        product_type: [{ type: 'custom_type' }],
        supported_currencies: ['KWD', 'SAR', 'USD', 'BHD', 'EUR', 'OMR', 'QAR', 'AED'],
        plugin_url: '/wp-content/plugins/supcheckout/',
        translation: {
            save_card_label: 'Save card',
            saved_card_fallback: 'Saved card'
        }
    }, overrides || {});

    let registered = null;
    let extensionData = { upayments: {} };

    const wc = {
        wcBlocksRegistry: {
            registerPaymentMethod(config) {
                registered = config;
            }
        },
        wcSettings: {
            getPaymentMethodData(name) {
                if (name !== 'upayments') throw new Error('Unexpected payment method: ' + name);
                return settings;
            }
        }
    };

    const wp = {
        data: {
            useDispatch() {
                return {
                    setExtensionData(namespace, data) {
                        extensionData = Object.assign({}, extensionData, { [namespace]: data });
                    }
                };
            },
            useSelect(selector) {
                return selector(() => ({
                    getExtensionData() {
                        return extensionData;
                    }
                }));
            },
            select() {
                return {
                    getExtensionData() {
                        return extensionData;
                    }
                };
            }
        },
        element: {
            createElement(type, props, ...children) {
                return { type, props: props || {}, children };
            },
            useEffect() {},
            useState(initial) {
                return [initial, function () {}];
            }
        }
    };

    const window = { wc, wp };
    const sandbox = {
        console,
        window,
        setTimeout() { return 1; }
    };

    vm.runInNewContext(source, sandbox, { filename: SOURCE });
    if (!registered) throw new Error('UPayments Blocks method did not register');

    return {
        registered,
        render(props) {
            if (!registered.content || typeof registered.content.type !== 'function') {
                throw new Error('Registered Blocks content does not expose the component function');
            }
            return registered.content.type(props || {});
        }
    };
}

console.log('Running e1-blocks-live-props-harness.js');

{
    const scene = createScene({ product_type: [{ type: 'custom_type' }] });
    const tree = scene.render({ cartData: { cartItems: [{ type: 'simple' }] } });
    record(!containsClass(tree, 'upay-subscription-wrapper'),
        'live simple cart suppresses stale static subscription-product fallback');
}

{
    const scene = createScene({ product_type: [{ type: 'simple' }] });
    const tree = scene.render({ cartData: { cartItems: [{ type: 'custom_type' }] } });
    record(containsClass(tree, 'upay-subscription-wrapper'),
        'live custom-type cart enables subscription UI despite stale static fallback');
}

{
    const scene = createScene({ product_type: [{ type: 'custom_type' }] });
    const tree = scene.render({});
    record(containsClass(tree, 'upay-subscription-wrapper'),
        'static product-type snapshot remains a bounded fallback when live cart props are absent');
}

{
    const scene = createScene();
    record(scene.registered.canMakePayment({ cartTotals: { currency_code: 'KWD' } }) === true,
        'Blocks canMakePayment accepts a hydrated supported live currency');
    record(scene.registered.canMakePayment({ cartTotals: { currency_code: 'JPY' } }) === false,
        'Blocks canMakePayment rejects a hydrated unsupported live currency');
    record(scene.registered.canMakePayment({ cartTotals: { currency_code: '' } }) === false,
        'Blocks canMakePayment fails closed while live currency is unhydrated');
    record(scene.registered.canMakePayment({}) === false,
        'Blocks canMakePayment fails closed when current-order totals are absent');
}

{
    const scene = createScene({ availability_valid: false });
    record(scene.registered.canMakePayment({ cartTotals: { currency_code: 'KWD' } }) === false,
        'provider availability failure remains authoritative for supported currencies');
}

console.log('Blocks live-props results: ' + pass + ' passed, ' + fail + ' failed.');
if (fail > 0) process.exitCode = 1;
