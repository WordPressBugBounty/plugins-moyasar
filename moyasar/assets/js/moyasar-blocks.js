/* global wc, wp, jQuery, ApplePaySession, SamsungPay */

/**
 * Moyasar — WooCommerce Cart/Checkout Blocks integration.
 *
 * Registers every Moyasar payment method (Credit Card, Apple Pay, STC Pay,
 * Samsung Pay, Invoice) with the WooCommerce Blocks checkout. Written in plain
 * wp.element (no JSX / build step) to match the rest of the plugin's assets.
 */
(function () {
    'use strict';

    if (!window.wc || !window.wc.wcBlocksRegistry || !window.wp || !window.wp.element) {
        console.error('Moyasar Blocks: WooCommerce Blocks registry or wp.element not found.');
        return;
    }

    var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
    var getSetting = window.wc.wcSettings.getSetting;
    var el = window.wp.element.createElement;
    var Fragment = window.wp.element.Fragment;
    var useState = window.wp.element.useState;
    var useRef = window.wp.element.useRef;
    var useEffect = window.wp.element.useEffect;
    var __ = window.wp.i18n.__;
    var decodeEntities = (window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities) || function (s) { return s; };

    /**
     * Shared label: method title plus its icon(s).
     */
    function Label(props) {
        var s = props.settings;
        var children = [decodeEntities(s.title || '')];
        var icons = (s.icons || []).filter(Boolean);
        if (icons.length) {
            var defaultHeight = (s.name === 'moyasar_cc') ? '20px' : '26px';
            var iconHeight = (s.icon_height && !isNaN(parseInt(s.icon_height, 10))) ? parseInt(s.icon_height, 10) + 'px' : defaultHeight;
            children.push(
                el('span', {
                    key: 'icons',
                    className: 'moyasar-blocks-icons moyasar-blocks-icons-' + s.name,
                    style: { 
                        display: 'inline-flex', 
                        gap: '4px', 
                        marginInlineStart: '8px', 
                        alignItems: 'center',
                        '--moyasar-icon-height': iconHeight
                    }
                }, icons.map(function (url, i) {
                    return el('img', { key: i, src: url, alt: '', style: { height: 'var(--moyasar-icon-height)', width: 'auto' } });
                }))
            );
        }
        return el('span', {
            className: 'moyasar-blocks-label',
            style: { display: 'inline-flex', alignItems: 'center', justifyContent: 'space-between', width: '100%' }
        }, children);
    }

    /**
     * Show custom error notices in the WooCommerce blocks checkout UI.
     */
    function showError(message) {
        try {
            if (window.wp && window.wp.data && window.wp.data.dispatch) {
                window.wp.data.dispatch('core/notices').createErrorNotice(message, {
                    id: 'moyasar-error-notice',
                    context: 'wc/checkout'
                });
            } else {
                console.error('Moyasar Checkout Error:', message);
            }
        } catch (e) {
            console.error('Moyasar Checkout Error:', message, e);
        }
    }

    /**
     * Toggle the Blocks "Place Order" button (used by wallet methods that supply
     * their own button).
     */
    function togglePlaceOrderButton(hide) {
        var btn = document.querySelector('.wc-block-components-checkout-place-order-button');
        if (btn) {
            btn.style.display = hide ? 'none' : '';
        }
    }

    /* --------------------------------------------------------------------- */
    /* Credit Card                                                           */
    /* --------------------------------------------------------------------- */

    function CreditCardContent(props) {
        var s = props.settings;
        if (!props.eventRegistration || !props.emitResponse) {
            return el('div', null, s.description ? decodeEntities(s.description) : decodeEntities(s.title || ''));
        }
        var strings = s.strings || {};
        var onPaymentSetup = props.eventRegistration.onPaymentSetup;
        var responseTypes = props.emitResponse.responseTypes;

        var stateInit = { name: '', number: '', expiry: '', cvc: '', save: false };
        var stateHook = useState(stateInit);
        var state = stateHook[0];
        var setState = stateHook[1];

        // Mirror state into a ref so the async onPaymentSetup closure reads current values.
        var ref = useRef(state);
        ref.current = state;

        function update(field, value) {
            setState(function (prev) {
                var next = Object.assign({}, prev);
                next[field] = value;
                return next;
            });
        }

        function formatNumber(v) {
            var raw = v.replace(/\D/g, '').substring(0, 19);
            return raw.replace(/(.{4})/g, '$1 ').trim();
        }

        function formatExpiry(v) {
            var raw = v.replace(/\D/g, '').substring(0, 4);
            if (raw.length >= 3) {
                return raw.substring(0, 2) + ' / ' + raw.substring(2);
            }
            return raw;
        }

        useEffect(function () {
            var unsubscribe = onPaymentSetup(function () {
                var d = ref.current;
                var number = (d.number || '').replace(/\s+/g, '');
                var exp = (d.expiry || '').split('/');
                var month = exp[0] ? exp[0].trim() : '';
                var year = exp[1] ? exp[1].trim() : '';
                if (year.length === 2) {
                    year = '20' + year;
                }

                if (!d.name || !number || !d.cvc || !month || !year) {
                    showError(strings.error_incomplete_fields);
                    return { type: responseTypes.ERROR, message: strings.error_incomplete_fields };
                }

                var payload = {
                    name: d.name,
                    number: number,
                    cvc: d.cvc,
                    month: month,
                    year: year,
                    save_only: true,
                    publishable_api_key: s.publishable_key,
                    callback_url: window.location.href
                };

                return fetch(s.token_api_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Basic ' + btoa(s.publishable_key + ':'),
                        'version': s.plugin_version
                    },
                    body: JSON.stringify(payload)
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (!data || data.type === 'validation_error' || data.type === 'api_error' || (!data.id && data.message)) {
                            var msg = (data && data.message) || strings.error_unknown;
                            if (data && data.errors) {
                                var keys = Object.keys(data.errors);
                                if (keys.length) { msg = data.errors[keys[0]][0]; }
                            }
                            showError(msg);
                            return { type: responseTypes.ERROR, message: msg };
                        }
                        if (!data.id) {
                            showError(strings.error_unknown);
                            return { type: responseTypes.ERROR, message: strings.error_unknown };
                        }
                        return {
                            type: responseTypes.SUCCESS,
                            meta: {
                                paymentMethodData: {
                                    moyasar_token: data.id,
                                    moyasar_save_card: d.save ? 'true' : 'false',
                                    mysr_form: 'blocks'
                                }
                            }
                        };
                    })
                    .catch(function () {
                        showError(strings.error_connection);
                        return { type: responseTypes.ERROR, message: strings.error_connection };
                    });
            });
            return function () { unsubscribe(); };
        }, [onPaymentSetup, responseTypes.ERROR, responseTypes.SUCCESS, s]);

        var inputStyle = {
            boxSizing: 'border-box',
            width: '100%',
            padding: '12px',
            border: '1px solid #ccc',
            borderRadius: '5px',
            direction: 'ltr',
            height: '44px',
            fontSize: '15px'
        };

        var rows = [];

        if (s.description) {
            rows.push(el('p', { key: 'desc', className: 'moyasar-blocks-desc' }, decodeEntities(s.description)));
        }

        rows.push(el('div', { key: 'name', className: 'moyasar-blocks-field' }, [
            el('label', { key: 'l' }, strings.cardholder_name + ' *'),
            el('input', {
                key: 'i',
                type: 'text',
                autoComplete: 'cc-name',
                placeholder: strings.cardholder_name_placeholder || 'Name on Card',
                value: state.name,
                style: inputStyle,
                onChange: function (e) { update('name', e.target.value); }
            })
        ]));

        rows.push(el('div', { key: 'number', className: 'moyasar-blocks-field' }, [
            el('label', { key: 'l' }, strings.card_number + ' *'),
            el('input', {
                key: 'i',
                type: 'text',
                inputMode: 'numeric',
                autoComplete: 'cc-number',
                placeholder: '1234 5678 9012 3456',
                value: state.number,
                style: inputStyle,
                onChange: function (e) { update('number', formatNumber(e.target.value)); }
            })
        ]));

        rows.push(el('div', {
            key: 'exprow',
            className: 'moyasar-blocks-field-group',
            style: { display: 'flex', gap: '10px' }
        }, [
            el('div', { key: 'exp', style: { flex: 1 } }, [
                el('label', { key: 'l' }, strings.expiry + ' *'),
                el('input', {
                    key: 'i',
                    type: 'text',
                    inputMode: 'numeric',
                    autoComplete: 'cc-exp',
                    placeholder: 'MM / YY',
                    value: state.expiry,
                    style: inputStyle,
                    onChange: function (e) { update('expiry', formatExpiry(e.target.value)); }
                })
            ]),
            el('div', { key: 'cvc', style: { flex: 1 } }, [
                el('label', { key: 'l' }, strings.cvc + ' *'),
                el('input', {
                    key: 'i',
                    type: 'password',
                    inputMode: 'numeric',
                    autoComplete: 'cc-csc',
                    placeholder: '123',
                    maxLength: 4,
                    value: state.cvc,
                    style: inputStyle,
                    onChange: function (e) { update('cvc', e.target.value.replace(/\D/g, '').substring(0, 4)); }
                })
            ])
        ]));

        if (s.tokenization && s.logged_in) {
            rows.push(el('div', { key: 'save', className: 'moyasar-blocks-field', style: { marginTop: '8px' } }, [
                el('label', { key: 'l', style: { display: 'inline-flex', alignItems: 'center', gap: '6px' } }, [
                    el('input', {
                        key: 'i',
                        type: 'checkbox',
                        checked: state.save,
                        onChange: function (e) { update('save', e.target.checked); }
                    }),
                    el('span', { key: 's' }, strings.save_to_account)
                ])
            ]));
        }

        return el('div', { className: 'moyasar-blocks-cc' }, rows);
    }

    /* --------------------------------------------------------------------- */
    /* Apple Pay                                                             */
    /* --------------------------------------------------------------------- */

    function ApplePayContent(props) {
        var s = props.settings;
        if (!props.eventRegistration || !props.emitResponse) {
            return el('div', null, s.description ? decodeEntities(s.description) : decodeEntities(s.title || ''));
        }
        var strings = s.strings || {};
        var billing = props.billing;
        var onSubmit = props.onSubmit;
        var reg = props.eventRegistration;
        var responseTypes = props.emitResponse.responseTypes;

        var tokenRef = useRef(null);
        var sessionRef = useRef(null);
        var errorHook = useState('');
        var error = errorHook[0];
        var setError = errorHook[1];

        function majorAmount() {
            if (!billing || !billing.cartTotal) {
                return '0.00';
            }
            var minorUnit = (billing.currency && typeof billing.currency.minorUnit === 'number') ? billing.currency.minorUnit : 2;
            var value = parseInt(billing.cartTotal.value, 10) || 0;
            return (value / Math.pow(10, minorUnit)).toFixed(minorUnit);
        }

        function startSession(e) {
            e.preventDefault();
            setError('');
            try {
                var supportedNetworks = ((s.supported_networks && s.supported_networks.length) ? s.supported_networks : ['visa', 'mastercard', 'amex', 'mada'])
                    .map(function(n) {
                        var brand = n.toLowerCase();
                        if (brand === 'mastercard') return 'masterCard';
                        if (brand === 'unionpay') return 'chinaUnionPay';
                        return brand;
                    });
                var request = {
                    countryCode: s.country || 'SA',
                    currencyCode: s.currency,
                    supportedNetworks: supportedNetworks,
                    supportedCountries: s.supported_countries || ['SA'],
                    merchantCapabilities: ['supports3DS', 'supportsDebit', 'supportsCredit'],
                    total: { label: s.store_name, amount: majorAmount() }
                };
                var session = new ApplePaySession(6, request);
                sessionRef.current = session;

                session.onvalidatemerchant = function (event) {
                    var url = (s.base_url || 'https://api.moyasar.com/v1/') + 'applepay/initiate';
                    fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            validation_url: event.validationURL,
                            display_name: s.store_name,
                            domain_name: window.location.hostname,
                            publishable_api_key: s.publishable_key
                        })
                    })
                        .then(function (res) { if (!res.ok) { throw new Error('validation'); } return res.json(); })
                        .then(function (data) { session.completeMerchantValidation(data); })
                        .catch(function () {
                            session.abort();
                            setError(strings.error_merchant_validation);
                            showError(strings.error_merchant_validation);
                        });
                };

                session.onpaymentauthorized = function (event) {
                    tokenRef.current = JSON.stringify(event.payment.token);
                    // Defer completePayment until the server confirms via onCheckoutSuccess/Fail.
                    onSubmit();

                    // Watchdog timer: If checkout is aborted due to client-side validation errors,
                    // close the Apple Pay sheet so it doesn't get stuck on "Processing...".
                    var checkInterval = setInterval(function () {
                        if (window.wp && window.wp.data) {
                            var checkoutStore = window.wp.data.select('wc/store/checkout');
                            var validationStore = window.wp.data.select('wc/store/validation');
                            
                            if (checkoutStore && !checkoutStore.isProcessing() && validationStore && validationStore.hasValidationErrors()) {
                                clearInterval(checkInterval);
                                if (sessionRef.current) {
                                    try { sessionRef.current.completePayment(ApplePaySession.STATUS_FAILURE); } catch (e) {}
                                    sessionRef.current = null;
                                }
                                tokenRef.current = null;
                                setError(strings.error_payment_failed || 'Checkout validation failed.');
                            }
                        }
                    }, 500);

                    // Safety clear after 10 seconds
                    setTimeout(function () {
                        clearInterval(checkInterval);
                    }, 10000);
                };

                session.oncancel = function () {
                    sessionRef.current = null;
                };

                session.begin();
            } catch (err) {
                setError(err.message || strings.error_payment_failed);
                showError(err.message || strings.error_payment_failed);
            }
        }

        useEffect(function () {
            togglePlaceOrderButton(true);

            var unsubSetup = reg.onPaymentSetup(function () {
                if (!tokenRef.current) {
                    setError(strings.error_payment_failed);
                    showError(strings.error_payment_failed);
                    return { type: responseTypes.ERROR, message: strings.error_payment_failed };
                }
                return {
                    type: responseTypes.SUCCESS,
                    meta: { paymentMethodData: { moyasar_apple_pay_token: tokenRef.current, mysr_form: 'blocks' } }
                };
            });

            var unsubSuccess = reg.onCheckoutSuccess(function () {
                if (sessionRef.current) {
                    try { sessionRef.current.completePayment(ApplePaySession.STATUS_SUCCESS); } catch (e) {}
                    sessionRef.current = null;
                }
                return true;
            });

            var unsubFail = reg.onCheckoutFail(function () {
                if (sessionRef.current) {
                    try { sessionRef.current.completePayment(ApplePaySession.STATUS_FAILURE); } catch (e) {}
                    sessionRef.current = null;
                }
                tokenRef.current = null;
                return true;
            });

            return function () {
                togglePlaceOrderButton(false);
                unsubSetup();
                unsubSuccess();
                unsubFail();
            };
        }, [reg.onPaymentSetup, reg.onCheckoutSuccess, reg.onCheckoutFail, responseTypes, s]);

        var theme = s.button_theme === 'light' ? 'white' : (s.button_theme === 'light-outline' ? 'white-outline' : 'black');
        var borderRadius = (s.border_radius !== undefined && s.border_radius !== '') ? s.border_radius : '5';

        return el('div', { className: 'moyasar-blocks-apple-pay' }, [
            s.description ? el('p', { key: 'desc', className: 'moyasar-blocks-desc' }, decodeEntities(s.description)) : null,
            el('div', {
                key: 'btn-wrapper',
                style: { display: 'block', width: '100%', cursor: 'pointer' },
                onClick: startSession
            }, el('apple-pay-button', {
                key: 'btn',
                buttonstyle: theme,
                type: 'buy',
                locale: s.locale || 'en',
                role: 'button',
                tabIndex: 0,
                style: {
                    '--apple-pay-button-width': '100%',
                    '--apple-pay-button-height': '44px',
                    '--apple-pay-button-border-radius': borderRadius + 'px',
                    borderRadius: borderRadius + 'px',
                    display: 'block',
                    width: '100%',
                    pointerEvents: 'none'
                }
            })),
            error ? el('p', { key: 'err', style: { color: 'red', marginTop: '10px' } }, error) : null
        ]);
    }

    /* --------------------------------------------------------------------- */
    /* STC Pay                                                               */
    /* --------------------------------------------------------------------- */

    function StcPayContent(props) {
        var s = props.settings;
        if (!props.eventRegistration || !props.emitResponse) {
            return el('div', null, s.description ? decodeEntities(s.description) : decodeEntities(s.title || ''));
        }
        var strings = s.strings || {};
        var reg = props.eventRegistration;
        var responseTypes = props.emitResponse.responseTypes;

        var mobileHook = useState('');
        var mobile = mobileHook[0];
        var setMobile = mobileHook[1];
        var mobileRef = useRef(mobile);
        mobileRef.current = mobile;

        function normalize(v) {
            var m = v.replace(/[\s-]/g, '');
            if (m.indexOf('+966') === 0) { m = '0' + m.slice(4); }
            else if (m.indexOf('00966') === 0) { m = '0' + m.slice(5); }
            else if (m.indexOf('966') === 0) { m = '0' + m.slice(3); }
            else if (m.indexOf('5') === 0) { m = '0' + m; }
            return m;
        }

        function showOtpModal(paymentId) {
            var overlay = document.createElement('div');
            overlay.className = 'moyasar-modal-overlay';
            overlay.setAttribute('style', 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:99999;display:flex;justify-content:center;align-items:center;');
            overlay.innerHTML =
                '<div class="moyasar-modal-content" style="background:#fff;width:90%;max-width:400px;padding:25px;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.2);position:relative;text-align:center;">' +
                '<button type="button" class="moyasar-modal-close" style="position:absolute;top:10px;right:15px;background:none;border:none;font-size:24px;cursor:pointer;color:#999;">&times;</button>' +
                '<h3 style="margin-top:0;color:#333;">' + strings.stc_pay_verification + '</h3>' +
                '<p style="color:#666;font-size:14px;margin-bottom:20px;">' + strings.enter_otp_message + '</p>' +
                '<div style="margin-bottom:20px;"><input type="text" id="moyasar_stc_otp" placeholder="' + strings.enter_otp_placeholder + '" style="box-sizing:border-box;width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:16px;text-align:center;letter-spacing:2px;" autocomplete="one-time-code"></div>' +
                '<button type="button" id="moyasar_stc_confirm_btn" style="width:100%;padding:12px;background:#4f2c85;color:#fff;border:none;border-radius:4px;font-size:16px;cursor:pointer;">' + strings.confirm_payment_btn + '</button>' +
                '<div id="moyasar_stc_error" style="color:red;font-size:13px;margin-top:10px;display:none;"></div>' +
                '</div>';
            document.body.appendChild(overlay);

            var otpInput = overlay.querySelector('#moyasar_stc_otp');
            var confirmBtn = overlay.querySelector('#moyasar_stc_confirm_btn');
            var errorBox = overlay.querySelector('#moyasar_stc_error');
            otpInput.focus();

            function cleanup() {
                if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
            }

            overlay.querySelector('.moyasar-modal-close').addEventListener('click', function () {
                cleanup();
                window.location.reload();
            });

            function confirm() {
                var otp = otpInput.value;
                if (!otp) {
                    errorBox.textContent = strings.otp_required;
                    errorBox.style.display = 'block';
                    return;
                }
                confirmBtn.disabled = true;
                confirmBtn.textContent = strings.processing_payment;
                errorBox.style.display = 'none';

                jQuery.post(s.ajax_url, {
                    action: 'moyasar_stc_pay_confirm',
                    nonce: s.confirm_nonce,
                    payment_id: paymentId,
                    otp: otp
                }).done(function (response) {
                    if (response.success && response.data.redirect_url) {
                        window.location.href = response.data.redirect_url;
                    } else {
                        errorBox.textContent = (response.data && response.data.message) || strings.verification_failed;
                        errorBox.style.display = 'block';
                        confirmBtn.disabled = false;
                        confirmBtn.textContent = strings.confirm_payment_btn;
                    }
                }).fail(function () {
                    errorBox.textContent = strings.error_payment_failed;
                    errorBox.style.display = 'block';
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = strings.confirm_payment_btn;
                });
            }

            confirmBtn.addEventListener('click', confirm);
            otpInput.addEventListener('keypress', function (e) { if (e.which === 13) { confirm(); } });
        }

        useEffect(function () {
            var unsubSetup = reg.onPaymentSetup(function () {
                var m = normalize(mobileRef.current || '');
                if (!/^05\d{8}$/.test(m)) {
                    showError(strings.error_invalid_mobile);
                    return { type: responseTypes.ERROR, message: strings.error_invalid_mobile };
                }
                return {
                    type: responseTypes.SUCCESS,
                    meta: { paymentMethodData: { moyasar_stc_pay_mobile: m, mysr_form: 'blocks' } }
                };
            });

            var unsubSuccess = reg.onCheckoutSuccess(function (data) {
                var details = {};
                if (data) {
                    if (data.paymentResult && data.paymentResult.paymentDetails) {
                        details = data.paymentResult.paymentDetails;
                    } else if (data.processingResponse && data.processingResponse.paymentDetails) {
                        details = data.processingResponse.paymentDetails;
                    }
                }
                if (details.moyasar_stc_otp === 'yes' && details.moyasar_stc_payment_id) {
                    // Hold the checkout open while the shopper enters the OTP; on success
                    // we navigate manually. The returned promise never resolves — the OTP
                    // confirm handler redirects (paid) or reloads (cancel).
                    showOtpModal(details.moyasar_stc_payment_id);
                    return new Promise(function () {});
                }
                return true;
            });

            return function () {
                unsubSetup();
                unsubSuccess();
            };
        }, [reg.onPaymentSetup, reg.onCheckoutSuccess, responseTypes, s]);

        return el('div', { className: 'moyasar-blocks-stc' }, [
            s.description ? el('p', { key: 'desc', className: 'moyasar-blocks-desc' }, decodeEntities(s.description)) : null,
            el('div', { key: 'field', className: 'moyasar-blocks-field' }, [
                el('label', { key: 'l' }, __('Mobile Number', 'moyasar-payments') + ' *'),
                el('input', {
                    key: 'i',
                    type: 'text',
                    inputMode: 'tel',
                    placeholder: '05xxxxxxxx',
                    value: mobile,
                    style: { 
                        boxSizing: 'border-box',
                        width: '100%', 
                        padding: '12px', 
                        border: '1px solid #ccc', 
                        borderRadius: '5px', 
                        direction: 'ltr',
                        height: '44px',
                        fontSize: '15px'
                    },
                    onChange: function (e) { setMobile(e.target.value); }
                })
            ])
        ]);
    }

    /* --------------------------------------------------------------------- */
    /* Samsung Pay                                                           */
    /* --------------------------------------------------------------------- */

    function SamsungPayContent(props) {
        var s = props.settings;
        if (!props.eventRegistration || !props.emitResponse) {
            return el('div', null, s.description ? decodeEntities(s.description) : decodeEntities(s.title || ''));
        }
        var strings = s.strings || {};
        var billing = props.billing;
        var onSubmit = props.onSubmit;
        var reg = props.eventRegistration;
        var responseTypes = props.emitResponse.responseTypes;

        var tokenRef = useRef(null);
        var errorHook = useState('');
        var error = errorHook[0];
        var setError = errorHook[1];

        function majorAmount() {
            if (!billing || !billing.cartTotal) {
                return '0.00';
            }
            var minorUnit = (billing.currency && typeof billing.currency.minorUnit === 'number') ? billing.currency.minorUnit : 2;
            var value = parseInt(billing.cartTotal.value, 10) || 0;
            return (value / Math.pow(10, minorUnit)).toFixed(minorUnit);
        }

        function handleClick(e) {
            e.preventDefault();
            setError('');
            var client;
            try {
                client = new SamsungPay.PaymentClient({ environment: s.environment });
            } catch (err) {
                setError(strings.error_payment_failed);
                return;
            }

            // Samsung Pay Web SDK only accepts these brands:
            // https://developer.samsung.com/pay/web/api-reference.html
            var SAMSUNG_PAY_NETWORKS = ['visa', 'mastercard', 'amex', 'mada'];
            var samsungAllowedBrands = (s.supported_networks || [])
                .map(function(n) { return n.toLowerCase(); })
                .filter(function(n) { return SAMSUNG_PAY_NETWORKS.indexOf(n) !== -1; });

            var paymentMethods = {
                version: '2',
                serviceId: s.service_id,
                protocol: 'PROTOCOL_3DS',
                allowedBrands: samsungAllowedBrands
            };

            var transactionDetail = {
                orderNumber: 'ORDER-' + window.location.hostname + '-' + (billing && billing.cartTotal ? billing.cartTotal.value : '0'),
                merchant: {
                    name: s.store_name,
                    url: window.location.hostname,
                    id: s.service_id,
                    countryCode: s.country
                },
                amount: {
                    option: 'FORMAT_TOTAL_PRICE_ONLY',
                    currency: s.currency,
                    total: majorAmount()
                }
            };

            client.loadPaymentSheet(paymentMethods, transactionDetail)
                .then(function (paymentCredential) {
                    tokenRef.current = JSON.stringify(paymentCredential);
                    onSubmit();
                })
                .catch(function () {
                    setError(strings.error_payment_failed);
                });
        }

        useEffect(function () {
            togglePlaceOrderButton(true);

            var unsubSetup = reg.onPaymentSetup(function () {
                if (!tokenRef.current) {
                    showError(strings.error_payment_failed);
                    return { type: responseTypes.ERROR, message: strings.error_payment_failed };
                }
                return {
                    type: responseTypes.SUCCESS,
                    meta: { paymentMethodData: { moyasar_samsung_pay_token: tokenRef.current, mysr_form: 'blocks' } }
                };
            });

            var unsubFail = reg.onCheckoutFail(function () {
                tokenRef.current = null;
                return true;
            });

            return function () {
                togglePlaceOrderButton(false);
                unsubSetup();
                unsubFail();
            };
        }, [reg.onPaymentSetup, reg.onCheckoutFail, responseTypes, s]);

        return el('div', { className: 'moyasar-blocks-samsung' }, [
            s.description ? el('p', { key: 'desc', className: 'moyasar-blocks-desc' }, decodeEntities(s.description)) : null,
            el('div', {
                key: 'btn',
                className: 'moyasar-samsung-pay-btn',
                style: {
                    backgroundColor: 'black', color: 'white', borderRadius: '4px', cursor: 'pointer',
                    display: 'flex', justifyContent: 'center', alignItems: 'center', height: '44px',
                    fontSize: '16px', marginTop: '10px'
                },
                onClick: handleClick
            }, __('Pay with Samsung Pay', 'moyasar-payments')),
            error ? el('p', { key: 'err', style: { color: 'red', marginTop: '10px' } }, error) : null
        ]);
    }

    /* --------------------------------------------------------------------- */
    /* Invoice (redirect)                                                    */
    /* --------------------------------------------------------------------- */

    function InvoiceContent(props) {
        var s = props.settings;
        return el('div', { className: 'moyasar-blocks-invoice' },
            el('p', { className: 'moyasar-blocks-desc' }, decodeEntities(s.description || s.title || ''))
        );
    }

    /* --------------------------------------------------------------------- */
    /* Registration                                                          */
    /* --------------------------------------------------------------------- */

    function register(name, Content, canMakePayment) {
        var settings = getSetting(name + '_data', null);
        if (!settings) {
            return;
        }
        registerPaymentMethod({
            name: name,
            label: el(Label, { settings: settings }),
            content: el(Content, { settings: settings }),
            edit: el(Content, { settings: settings }),
            canMakePayment: canMakePayment || function () { return true; },
            ariaLabel: decodeEntities(settings.title || name),
            supports: {
                features: settings.supports || ['products'],
                showSavedCards: false,
                showSaveOption: false
            }
        });
    }

    register('moyasar_cc', CreditCardContent);

    register('moyasar_apple_pay', ApplePayContent, function () {
        return typeof window.ApplePaySession !== 'undefined' &&
            window.ApplePaySession.canMakePayments &&
            window.ApplePaySession.canMakePayments();
    });

    register('moyasar_stc_pay', StcPayContent);

    register('moyasar_samsung_pay', SamsungPayContent, function () {
        var settings = getSetting('moyasar_samsung_pay_data', {});
        return typeof window.SamsungPay !== 'undefined' && !!settings.service_id;
    });

    register('moyasar_invoice', InvoiceContent);

})();
