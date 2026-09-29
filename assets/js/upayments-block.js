(function ( wc, wp ) {
'use strict';

if ( !wc || !wp || !wc.wcBlocksRegistry || !wc.wcSettings || !wp.data || !wp.element ) {
    return;
}

const { registerPaymentMethod } = wc.wcBlocksRegistry;

    const settings = window.wc.wcSettings.getPaymentMethodData('upayments') || {};
    const {
        availability_valid,
        supported_currencies = [],
        is_whitelabled,
        payment_icons,
        saved_cards,
        is_logged_in,
        save_card_enabled,
        is_subscription_enabled,
        product_type,
        plugin_url,
        translation
    } = settings;

    const Content = (props) => {
        const { useDispatch, useSelect } = wp.data;
        const { useEffect, useState, createElement } = wp.element;
        const { setExtensionData } = useDispatch('wc/store/checkout');
        const liveCartItems = props && props.cartData && Array.isArray(props.cartData.cartItems)
            ? props.cartData.cartItems
            : null;
        const hasCustomTypeProduct = Array.isArray(liveCartItems)
            ? liveCartItems.some(product => product && product.type === 'custom_type')
            : Array.isArray(product_type) && product_type.some(product => product && product.type === 'custom_type');

        const NAMESPACE = 'upayments';

        // Section AI: Current-store helper for event handlers.
        const getCurrentUpayData = () => {
            const all = wp.data.select('wc/store/checkout').getExtensionData() || {};
            const current = all[NAMESPACE];
            return (current && typeof current === 'object' && !Array.isArray(current))
                ? current
                : {};
        };

        // Reactive subscription via useSelect (Section AA).
        const upayData = useSelect(
            (select) => {
                const data = select('wc/store/checkout').getExtensionData() || {};
                return data[NAMESPACE] || {};
            },
            []
        );

        const [toast, setToast] = useState({ message: '', show: false });

        const showToast = (msg) => {
            setToast({ message: msg, show: true });
            setTimeout(() => setToast({ message: '', show: false }), 3500);
        };

        const text = (key) => (
            translation
            && typeof translation === 'object'
            && typeof translation[key] === 'string'
        ) ? translation[key] : '';
        const planLabels = (
            translation
            && translation.plan_labels
            && typeof translation.plan_labels === 'object'
        ) ? translation.plan_labels : {};
        const optionsData = (
            translation
            && translation.interval_labels
            && typeof translation.interval_labels === 'object'
        ) ? translation.interval_labels : {};

        const updateCheckout = (newData) => {
            const allExtensionData = wp.data.select('wc/store/checkout').getExtensionData() || {};
            const currentData = (allExtensionData[NAMESPACE] && typeof allExtensionData[NAMESPACE] === 'object')
                ? allExtensionData[NAMESPACE]
                : {};
            setExtensionData(NAMESPACE, {
                ...currentData,
                ...newData
            });
        };

        // Section AK: Subscription handler uses current store state.
const handleSubscriptionChange = (plan, interval) => {
    const current = getCurrentUpayData();
    let finalInterval;
    if (plan === 'one_time') {
        // Server-side one_time requires interval '0' to pass allowlist validation.
        finalInterval = '0';
    } else if (plan !== current.upay_subscription_plan) {
        // Switching to a non-one_time plan with no interval picked: reset.
        finalInterval = '';
    } else {
        finalInterval = interval;
    }
    updateCheckout({
        upay_subscription_plan: plan,
        upay_subscription_interval: finalInterval
    });
};

        // Section AJ: Method transitions use current store state.
        const handleMethodClick = (type, cardSelection = null) => {
            if (cardSelection) {
                // Saved card selected: the historical card_token extension key
                // carries only an opaque server-verifiable selection handle.
                updateCheckout({
                    upayment_payment_type: type,
                    card_token: cardSelection,
                    save_card: '0'
                });
                if (translation && typeof translation.saved_card_selected === 'string' && translation.saved_card_selected !== '') {
                    showToast(translation.saved_card_selected);
                }
            } else if (type === 'cc') {
                // Section AJ: New CC transition — check current store state.
                const current = getCurrentUpayData();
                const currentMethod = current.upayment_payment_type;
                const currentCardToken = current.card_token;
                const currentSaveCard = current.save_card;

                // Re-click current new CC with explicit consent: preserve.
                if (currentMethod === 'cc' && !currentCardToken && currentSaveCard === '1') {
                    updateCheckout({
                        upayment_payment_type: type,
                        card_token: null,
                        save_card: '1'
                    });
                } else {
                    // Transition into new CC: default to no consent.
                    updateCheckout({
                        upayment_payment_type: type,
                        card_token: null,
                        save_card: '0'
                    });
                }
            } else {
                // Non-CC: always clear card and save.
                updateCheckout({
                    upayment_payment_type: type,
                    card_token: null,
                    save_card: '0'
                });
            }
        };

        const normalizedChecked = is_logged_in && save_card_enabled && upayData.save_card === '1';

        const handleSaveCardToggle = (event) => {
            const checked = event.target.checked;
            const next = checked && is_logged_in && save_card_enabled;
            updateCheckout({ save_card: next ? '1' : '0' });
        };

        return createElement(
            'div',
            { className: 'upay-payment-container' },

            toast.show && createElement('div', {
                className: 'wc-toast show',
                role: 'status',
                'aria-live': 'polite',
                'aria-atomic': 'true',
                style: {
                    position: 'fixed', top: '30px', right: '30px', background: '#F23232',
                    color: '#fff', padding: '12px 18px', borderRadius: '6px', zIndex: 99999,
                    boxShadow: '0 4px 12px rgba(0,0,0,0.15)', transition: 'all 0.3s ease'
                }
            }, toast.message),

            // Section AL: Subscription UI requires Whitelabel + CC enabled.
            is_subscription_enabled && hasCustomTypeProduct && is_whitelabled && payment_icons && payment_icons.cc && createElement(
                'div',
                { className: 'upay-subscription-wrapper', style: { marginBottom: '20px', padding: '15px', background: '#f9f9f9', borderRadius: '8px', border: '1px solid #eee' } },
                createElement('label', { htmlFor: 'supcheckout-blocks-plan', style: { display: 'block', fontWeight: 'bold', marginBottom: '5px' } },
                    text('purchase_type_label') + ' ', createElement('span', { style: { color: 'red' }, 'aria-hidden': 'true' }, '*')
                ),
                createElement('select', {
                    id: 'supcheckout-blocks-plan',
                    value: upayData.upay_subscription_plan || 'one_time',
                    className: 'wc-block-components-select__input',
                    style: { width: '100%', padding: '10px', marginBottom: '15px' },
                    onChange: (e) => handleSubscriptionChange(e.target.value, upayData.upay_subscription_interval)
                },
                    createElement('option', { value: 'one_time' }, text('one_time')),
                    createElement('option', { value: 'daily' }, typeof planLabels.daily === 'string' ? planLabels.daily : ''),
                    createElement('option', { value: 'weekly' }, typeof planLabels.weekly === 'string' ? planLabels.weekly : ''),
                    createElement('option', { value: 'monthly' }, typeof planLabels.monthly === 'string' ? planLabels.monthly : ''),
                    createElement('option', { value: 'quarterly' }, typeof planLabels.quarterly === 'string' ? planLabels.quarterly : ''),
                    createElement('option', { value: 'yearly' }, typeof planLabels.yearly === 'string' ? planLabels.yearly : '')
                ),
                upayData.upay_subscription_plan && upayData.upay_subscription_plan !== 'one_time' && createElement('div', {},
                    createElement('label', { htmlFor: 'supcheckout-blocks-interval', style: { display: 'block', fontWeight: 'bold', marginBottom: '5px' } }, text('billing_interval_label') + ' ', createElement('span', { style: { color: 'red' }, 'aria-hidden': 'true' }, '*')),
                    createElement('select', {
                        id: 'supcheckout-blocks-interval',
                        'aria-required': 'true',
                        value: upayData.upay_subscription_interval || '',
                        className: 'wc-block-components-select__input',
                        style: { width: '100%', padding: '10px' },
                        onChange: (e) => handleSubscriptionChange(upayData.upay_subscription_plan, e.target.value)
                    },
                        createElement('option', { value: '' }, text('select_interval')),
                        optionsData[upayData.upay_subscription_plan] && Object.entries(optionsData[upayData.upay_subscription_plan]).map(([val, text]) => (
                            createElement('option', { key: val, value: val }, text)
                        ))
                    )
                )
            ),

            createElement('div', { className: 'form-row form-row-wide' },
                is_whitelabled ? createElement('div', { className: 'payment-sections' },

                    is_logged_in && Array.isArray(saved_cards) && saved_cards.length > 0 && [
                        createElement('h3', {
                                key: 'title-saved',
                                style: {
                                    fontSize: '16px',
                                    fontWeight: 'bold',
                                    margin: '20px 0 10px'
                                }
                            }, text('saved_cards_label')
                        ),
                        createElement('div', {
                            key: 'list-saved',
                            className: 'saved-cards-group'
                        },
                        saved_cards.map((card, index) =>
                            {
                                if (!card || typeof card !== 'object') return null;
                                const selection = typeof card.selection === 'string' && /^sc1_[0-9a-f]{64}$/.test(card.selection)
                                    ? card.selection
                                    : null;
                                if (!selection) return null;
                                const fallbackLabel = translation && typeof translation.saved_card_fallback === 'string'
                                    ? translation.saved_card_fallback
                                    : '';
                                const label = typeof card.label === 'string' && card.label !== '' ? card.label : fallbackLabel;
                                if (!label) return null;
                                const brand = typeof card.brand === 'string' ? card.brand : '';
                                return createElement('button',
                                    {
                                        key: selection || index,
                                        type: 'button',
                                        className: `upay-payment-method ${upayData.card_token === selection ? 'active' : ''}`,
                                        'aria-pressed': upayData.card_token === selection,
                                        onClick: () => handleMethodClick('cc', selection),
                                            style: {
                                                display: 'flex',
                                                width: '100%',
                                                padding: '12px',
                                                marginBottom: '8px',
                                                alignItems: 'center',
                                                borderRadius: '4px',
                                                background: '#fff',
                                                border: upayData.card_token === selection ? '2px solid #007cba' : '1px solid #ccc',
                                                cursor: 'pointer'
                                            }
                                    },
                                    createElement('span', { className: 'payment-method-icon' },
                                        createElement('img', {
                                            src: `${plugin_url}assets/images/cc.png`,
                                            alt: '',
                                            style: {
                                                height: '24px'
                                            }
                                        })
                                    ),
                                    createElement('span', {
                                        style: {
                                            marginLeft: '10px',
                                            flexGrow: 1,
                                            textAlign: 'left',
                                            fontSize: '14px'
                                        }
                                    }, brand ? `${label} (${brand})` : label),
                                    createElement('span', {
                              className: 'upay-chevron',
                              'aria-hidden': 'true',
                              style: {
                                  marginLeft: '10px',
                                  fontSize: '20px',
                                  lineHeight: '1'
                              }
                          }, '›')
                                );
                            })
                        )
                    ],

                    createElement('h3', {
                        style: {
                            fontSize: '16px',
                            fontWeight: 'bold',
                            margin: '25px 0 10px'
                        }
                    }, text('choose_payment_method')),
                    createElement('div', {
                        className: 'normal-methods-group'
                    },
                    Object.entries(payment_icons).map(([key, label]) => (
                        createElement('button',
                                {
                                    key: key,
                                    type: 'button',
                                    className: `upay-payment-method ${upayData.upayment_payment_type === key && !upayData.card_token ? 'active' : ''}`,
                                    'aria-pressed': upayData.upayment_payment_type === key && !upayData.card_token,
                                    onClick: () => handleMethodClick(key),
                                    style: {
                                        display: 'flex',
                                        width: '100%',
                                        padding: '12px',
                                        marginBottom: '8px',
                                        alignItems: 'center',
                                        borderRadius: '4px',
                                        background: '#fff',
                                        border: (upayData.upayment_payment_type === key && !upayData.card_token) ? '2px solid #007cba' : '1px solid #ccc'
                                    }
                                },
                                createElement('span', { className: 'payment-method-icon' },
                                    (() => {
                                        if (key === 'apple-pay-knet') {
                                            return [
                                                createElement('img', {
                                                    key: 'apple',
                                                    src: `${plugin_url}assets/images/apple-pay.png`,
                                                    alt: '',
                                                    style: {
                                                        height: '24px',
                                                        marginRight: '5px'
                                                    }
                                                }),
                                                createElement('img', {
                                                    key: 'knet',
                                                    src: `${plugin_url}assets/images/knet.png`,
                                                    alt: '',
                                                    style: {
                                                        height: '24px'
                                                    }
                                                })
                                            ];
                                        } else if (key === 'apple-pay') {
                                            return [
                                                createElement('img', {
                                                    key: 'apple',
                                                    src: `${plugin_url}assets/images/apple-pay.png`,
                                                    alt: '',
                                                    style: {
                                                        height: '24px',
                                                        marginRight: '5px'
                                                    }
                                                }),
                                                createElement('img', {
                                                    key: 'cc',
                                                    src: `${plugin_url}assets/images/cc.png`,
                                                    alt: '',
                                                    style: {
                                                        height: '24px'
                                                    }
                                                })
                                            ];
                                        }
                                        return createElement('img', {
                                            src: `${plugin_url}assets/images/${key}.png`,
                                            alt: '',
                                            style: {
                                                height: '24px'
                                            }
                                        });
                                    })()
                                ),
                                createElement('span', {
                                    style: {
                                        marginLeft: '10px',
                                        fontWeight: '600'
                                    }
                                }, label),
                                createElement('span', {
                              className: 'upay-chevron',
                              'aria-hidden': 'true',
                              style: {
                                  marginLeft: '10px',
                                  fontSize: '20px',
                                  lineHeight: '1'
                              }
                          }, '›')
                            )
                        ))
                    ),

                    is_logged_in && save_card_enabled && upayData.upayment_payment_type === 'cc' && !upayData.card_token && (
                        createElement('div', {
                                style: {
                                    display: 'flex',
                                    justifyContent: 'space-between',
                                    alignItems: 'center',
                                    padding: '15px',
                                    borderTop: '1px solid #eee',
                                    marginTop: '10px'
                                }
                            },
                            createElement('label', {
                                htmlFor: 'chkSaveCard',
                                style: {
                                    fontSize: '0.9em'
                                }
                            }, text('save_card_label')),
                            createElement('span', {
                                    className: 'switch'
                                },
                                createElement('input', {
                                    type: 'checkbox',
                                    id: 'chkSaveCard',
                                    checked: normalizedChecked,
                                    onChange: handleSaveCardToggle
                                }),
                                createElement('span', { className: 'slider round' })
                            )
                        )
                    )
                ) : (
                    createElement('div', { className: 'payment-buttons' },
                        createElement('div', {
                            className: 'upay-payment-method upay-payment-method--static',
                            style: { display: 'flex', width: '100%', padding: '15px', alignItems: 'center', border: '1px solid #ccc', borderRadius: '4px', background: '#fff', cursor: 'default', boxSizing: 'border-box' }
                        },
                            Object.keys(payment_icons).map(key => (
                                key !== 'apple-pay-knet' && createElement('span', { key, style: { marginRight: '8px' } },
                                    createElement('img', { src: `${plugin_url}assets/images/${key}.png`, alt: '', style: { height: '22px' } })
                                )
                            )),
                            createElement('span', {
                              className: 'upay-chevron',
                              'aria-hidden': 'true',
                              style: {
                                  marginLeft: 'auto',
                                  fontSize: '20px',
                                  lineHeight: '1'
                              }
                          }, '›')
                        )
                    )
                )
            )
        );
    };

    const canMakePayment = (currentOrder) => {
        const liveCurrency = currentOrder
            && currentOrder.cartTotals
            && typeof currentOrder.cartTotals.currency_code === 'string'
            ? currentOrder.cartTotals.currency_code
            : '';

        return availability_valid === true
            && liveCurrency !== ''
            && Array.isArray(supported_currencies)
            && supported_currencies.includes(liveCurrency);
    };

    registerPaymentMethod({
        name: 'upayments',
        label: 'UPayments',

        content: wp.element.createElement(Content),
        edit: wp.element.createElement(Content),

        canMakePayment: canMakePayment,

        ariaLabel: 'UPayments',

        supports: {
            features: [ 'products' ],
        },

        onPaymentMethodChange: () => {
            return true;
        }
    });

})( window.wc, window.wp );
