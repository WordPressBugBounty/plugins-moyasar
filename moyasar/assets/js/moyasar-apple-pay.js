/* global moyasar_apple_params, ApplePaySession, jQuery */
(function ($) {
    'use strict';

    /**
     * Moyasar Apple Pay Handler
     * 
     * Handles Apple Pay interactions on Product and Checkout pages.
     * Encapsulates session management, validation, and payment processing.
     * 
     * @class MoyasarApplePay
     */
    class MoyasarApplePay {

        constructor() {
            // Configuration from localized script
            this.config = moyasar_apple_params;

            // DOM Selectors
            this.selectors = {
                productContainer: '#moyasar-apple-pay-product-container',
                checkoutContainer: '#moyasar-apple-pay-button-container',
                productError: '#moyasar-apple-pay-product-error',
                placeOrderBtn: '#place_order, button[name="woocommerce_checkout_place_order"]',
                paymentRadio: 'input[name="payment_method"]',
                quantityInput: 'form.cart input[name="quantity"]',
                checkoutForm: 'form.checkout'
            };
        }

        /**
         * Initialize the Apple Pay handler
         */
        init() {
            if (!this.isApplePaySupported()) {
                // Apple Pay only works in Safari on Apple devices. On every other
                // device/browser hide the checkout payment option so customers
                // can't select a method they are unable to complete.
                this.hideUnsupportedPaymentMethod();
                return;
            }

            // Render buttons based on the current page context
            this.renderButtons();

            // Bind global events for checkout toggling
            this.bindEvents();
        }

        /**
         * Hide the Apple Pay payment method on the classic checkout when the
         * current device/browser does not support Apple Pay, and move the
         * selection to another available method if it was selected.
         */
        hideUnsupportedPaymentMethod() {
            const hide = () => {
                const $li = $('li.payment_method_moyasar_apple_pay');
                if (!$li.length) {
                    return;
                }

                $li.hide();

                const $radio = $li.find('input[name="payment_method"]');
                if ($radio.is(':checked')) {
                    // Select the first other visible payment method so the
                    // checkout remains usable.
                    const $next = $('li.wc_payment_method:visible input[name="payment_method"]')
                        .not($radio)
                        .first();
                    if ($next.length) {
                        $next.prop('checked', true).trigger('click').trigger('change');
                    }
                }
            };

            hide();

            // WooCommerce re-renders the payment methods list on AJAX updates,
            // so re-apply the hiding whenever that happens.
            $(document.body).on('updated_checkout', hide);
        }

        /**
         * Check if Apple Pay is supported and active in the current environment
         * 
         * @returns {boolean}
         */
        isApplePaySupported() {
            if (!window.ApplePaySession) {
                this.log('warn', 'Apple Pay is not supported in this browser.');
                return false;
            }

            if (!ApplePaySession.canMakePayments()) {
                this.log('warn', 'Apple Pay is supported but not active. Verify wallet setup.');
                return false;
            }

            if (location.protocol !== 'https:') {
                this.log('error', 'Apple Pay requires HTTPS.');
                return false;
            }

            return true;
        }

        /**
         * Render Apple Pay buttons in appropriate containers
         */
        renderButtons() {
            // Product Page Logic
            const $productContainer = $(this.selectors.productContainer);
            if ($productContainer.length) {
                this.createButton($productContainer, 'order');
            }

            // Checkout Page Logic
            // We check immediately in case we are already on the checkout page with Apple Pay selected
            this.toggleCheckoutButton();
        }

        /**
         * Bind specific page events
         */
        bindEvents() {
            $('body').on('change', this.selectors.paymentRadio, () => this.toggleCheckoutButton());

            $(document.body).on('updated_checkout', () => {
                this.toggleCheckoutButton();
                this.updateTotalFromDOM();
            });

            $(document.body).on('updated_wc_div', () => {
                this.updateTotalFromDOM();
            });

            $(document.body).on('checkout_error', () => this.setPaymentOptionDisabled(false));
        }

        setPaymentOptionDisabled(disabled) {
            $('ul.wc_payment_methods').toggleClass('moyasar-payment-processing', disabled);
        }

        updateTotalFromDOM() {
            const $el = $('.moyasar-update-total');
            if ($el.length) {
                // In case of multiple occurences, take the last one which is likely the most recent fragment update
                const newTotal = $el.last().val();
                if (newTotal !== undefined && newTotal !== null && newTotal !== '') {
                    this.config.total = newTotal.toString();
                    this.log('info', 'Updated Total from DOM Input:', newTotal);
                }
            }
        }

        /**
         * Toggle visibility and position of the Apple Pay button on checkout
         * Replaces the default "Place Order" button when Apple Pay is selected.
         */
        toggleCheckoutButton() {
            const selectedMethod = $(`${this.selectors.paymentRadio}:checked`).val();
            const $allPlaceOrderBtns = $(this.selectors.placeOrderBtn);
            const $applePayContainer = $(this.selectors.checkoutContainer);

            if (selectedMethod === 'moyasar_apple_pay') {
                // Determine target button to append to (prefer visible button to handle responsive themes)
                let $targetBtn = $allPlaceOrderBtns.filter(':visible').first();
                if (!$targetBtn.length) {
                    $targetBtn = $allPlaceOrderBtns.first();
                }

                $allPlaceOrderBtns.hide();

                // Reposition container to the target place order button's parent if needed
                const $placeOrderParent = $targetBtn.parent();
                if ($placeOrderParent.length && $applePayContainer.parent()[0] !== $placeOrderParent[0]) {
                    $applePayContainer.detach().appendTo($placeOrderParent);
                }

                // Render button if it doesn't already exist inside the container
                if (!$applePayContainer.children('apple-pay-button').length) {
                    $applePayContainer.empty();
                    this.createButton($applePayContainer, 'plain');
                }
                $applePayContainer.show();
            } else {
                // Do not show Place Order button if Samsung Pay is selected, as it has its own button
                if (selectedMethod !== 'moyasar_samsung_pay') {
                    $allPlaceOrderBtns.show();
                }
                $applePayContainer.hide();
            }
        }

        /**
         * Create and append the Apple Pay system button
         * 
         * @param {jQuery} $container Target container
         * @param {string} type Button type ('buy', 'order', 'plain')
         */
        createButton($container, type) {
            const lang = this.config.country || 'en';
            // Use configured label type if not forced
            const buttonType = type && type !== 'plain' ? type : (this.config.button_label || 'buy');
            const theme = this.config.button_theme || 'black';
            // Map theme to Apple Pay values: 'black', 'white', 'white-outline'
            let appleTheme = 'black';
            if (theme === 'light') appleTheme = 'white';
            if (theme === 'light-outline') appleTheme = 'white-outline';

            const height = this.config.button_height ? this.config.button_height + 'px' : '40px';
            const borderRadius = (this.config.apple_pay_border_radius !== undefined && this.config.apple_pay_border_radius !== '') ? this.config.apple_pay_border_radius : '5';

            const $button = $('<apple-pay-button>');
            $button.attr('buttonstyle', appleTheme);
            $button.attr('type', buttonType);
            $button.attr('locale', lang);
            $button.attr('role', 'button');
            $button.attr('tabindex', '0');
            $button.attr('style', `--apple-pay-button-height: ${height}; --apple-pay-button-width: 100%; --apple-pay-button-border-radius: ${borderRadius}px; border-radius: ${borderRadius}px; -apple-pay-button-type: ${buttonType}; -apple-pay-button-style: ${appleTheme}; width: 100%; display: inline-block; cursor: pointer;`);

            const domButton = $button[0];
            if (domButton) {
                domButton.addEventListener('click', (e) => this.startSession(e));
            } else {
                $button.on('click', (e) => this.startSession(e));
            }
            $container.append($button);
        }

        /**
         * Start the Apple Pay Session
         * 
         * @param {Event} e Click event
         */
        startSession(e) {
            e.preventDefault();

            try {
                // Only request contact fields if NOT on the checkout page (i.e. Product/Cart pages)
                // On Checkout page, we rely on the WooCommerce form fields.
                let contactFields = [];
                if (!this.config.is_checkout) {
                    contactFields = ['postalAddress', 'name', 'phone', 'email'];
                }

                let amount = this.config.total;
                if (this.config.is_product) {
                    const qtyVal = $(this.selectors.quantityInput).val();
                    const qty = qtyVal ? parseInt(qtyVal) : 1;
                    if (!isNaN(parseFloat(amount))) {
                        amount = (parseFloat(amount) * qty).toFixed(2);
                    }
                }

                // Map Moyasar's lowercase brand names to Apple Pay's exact network identifiers.
                // Matches the official payment form (mastercard -> masterCard, unionpay -> chinaUnionPay).
                const supportedNetworks = ((this.config.brands && this.config.brands.length)
                    ? this.config.brands
                    : ['visa', 'mastercard', 'amex', 'mada'])
                    .map((n) => {
                        const brand = n.toLowerCase();
                        if (brand === 'mastercard') return 'masterCard';
                        if (brand === 'unionpay') return 'chinaUnionPay';
                        return brand;
                    });

                const request = {
                    countryCode: this.config.country || 'SA',
                    currencyCode: this.config.currency,
                    supportedNetworks: supportedNetworks,
                    supportedCountries: this.config.countries || ['SA'],
                    merchantCapabilities: ['supports3DS', 'supportsDebit', 'supportsCredit'],
                    requiredBillingContactFields: contactFields,
                    requiredShippingContactFields: contactFields,
                    total: {
                        label: this.config.label,
                        amount: amount
                    }
                };

                const session = new ApplePaySession(6, request);

                // Hook up Session Events
                session.onvalidatemerchant = (event) => this.handleMerchantValidation(session, event);
                session.onpaymentauthorized = (event) => this.handlePaymentAuthorization(session, event);
                session.onshippingcontactselected = (event) => this.handleShippingContactSelection(session, event);
                session.onshippingmethodselected = (event) => this.handleShippingMethodSelection(session, event);
                session.oncancel = () => this.log('info', 'Apple Pay session cancelled by user.');

                session.begin();
            } catch (error) {
                this.log('error', 'Failed to start Apple Pay session', error);
                if (this.config.is_checkout) {
                    alert(error.message || 'Failed to start Apple Pay. Please try another payment method.');
                } else {
                    this.showError(error.message || 'Failed to start Apple Pay.');
                }
            }
        }

        /**
         * Handle Merchant Validation (Step 1)
         * 
         * @param {ApplePaySession} session 
         * @param {Event} event 
         */
        handleMerchantValidation(session, event) {
            // Use dynamic API URL
            const url = this.config.api_url ? this.config.api_url + 'applepay/initiate' : 'https://api.moyasar.com/v1/applepay/initiate';

            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    validation_url: event.validationURL,
                    display_name: this.config.label,
                    domain_name: window.location.hostname,
                    publishable_api_key: this.config.publishable_key
                })
            })
                .then(res => {
                    if (!res.ok) throw new Error('Validation API Error');
                    return res.json();
                })
                .then(data => session.completeMerchantValidation(data))
                .catch(err => {
                    this.log('error', 'Merchant Validation Failed', err);
                    session.abort();
                    this.showError(this.config.strings.error_merchant_validation);
                });
        }

        /**
         * Handle Payment Authorization (Step 2)
         * 
         * @param {ApplePaySession} session 
         * @param {Event} event 
         */
        handlePaymentAuthorization(session, event) {
            const token = JSON.stringify(event.payment.token);

            if (this.config.is_product) {
                // Use event data primarily, fallback to stored selection if missing
                const sc = event.payment.shippingContact || this.selectedShippingContact;
                const sm = event.payment.shippingMethod || this.selectedShippingMethod;
                this.processInstantPayment(session, token, sc, sm);
            } else {
                this.processCheckoutPayment(session, token);
            }
        }

        /**
         * Process Instant Payment specific logic (Product Page)
         * 
         * @param {ApplePaySession} session 
         * @param {string} token 
         * @param {object} shippingContact 
         * @param {object} shippingMethod
         */
        processInstantPayment(session, token, shippingContact, shippingMethod) {
            $.post(this.config.ajax_url, {
                action: 'moyasar_apple_pay_authorized',
                nonce: this.config.apple_pay_nonce,
                token: token,
                product_id: this.config.product_id,
                quantity: $(this.selectors.quantityInput).val() || 1,
                shipping_contact: JSON.stringify(shippingContact),
                shipping_method: shippingMethod ? JSON.stringify(shippingMethod) : ''
            })
                .done(response => {
                    if (response.success) {
                        session.completePayment(ApplePaySession.STATUS_SUCCESS);
                        window.location = response.data.url;
                    } else {
                        this.log('error', 'Payment Authorization Failed', response);
                        session.completePayment(ApplePaySession.STATUS_FAILURE);
                        this.showError(response.data.message || this.config.strings.error_payment_failed);
                    }
                })
                .fail(() => {
                    session.completePayment(ApplePaySession.STATUS_FAILURE);
                    this.showError(this.config.strings.error_connection);
                });
        }

        /**
         * Process Checkout Payment specific logic (Checkout Page)
         * 
         * @param {ApplePaySession} session 
         * @param {string} token 
         */
        processCheckoutPayment(session, token) {
            const $form = $(this.selectors.checkoutForm);

            // Clean previous tokens
            $form.find('input[name="moyasar_apple_pay_token"]').remove();

            // Inject new token
            $('<input>', {
                type: 'hidden',
                name: 'moyasar_apple_pay_token',
                value: token
            }).appendTo($form);

            session.completePayment(ApplePaySession.STATUS_SUCCESS);

            // Disable payment options to prevent re-submission during processing
            this.setPaymentOptionDisabled(true);

            // Submit WooCommerce Checkout Form
            $form.trigger('submit');
        }

        /**
         * Handle Shipping Contact Selection
         * Updates shipping methods and total costs based on address
         * 
         * @param {ApplePaySession} session 
         * @param {Event} event 
         */
        handleShippingContactSelection(session, event) {
            const contact = event.shippingContact;
            this.selectedShippingContact = contact;


            $.post(this.config.ajax_url, {
                action: 'moyasar_apple_pay_get_shipping_methods',
                nonce: this.config.apple_pay_nonce,
                product_id: this.config.product_id,
                quantity: $(this.selectors.quantityInput).val() || 1,
                countryCode: contact.countryCode,
                administrativeArea: contact.administrativeArea,
                locality: contact.locality,
                postalCode: contact.postalCode
            })
                .done(response => {
                    if (response.success) {
                        const data = response.data;

                        // Store subtotal with tax for recalculation during method selection
                        if (data.subtotal_with_tax) {
                            this.currentSubtotalWithTax = parseFloat(data.subtotal_with_tax);
                        }

                        const total = { label: this.config.label, amount: data.total };

                        session.completeShippingContactSelection(
                            ApplePaySession.STATUS_SUCCESS,
                            data.shippingMethods,
                            total,
                            data.lineItems
                        );
                    } else {
                        session.completeShippingContactSelection(
                            ApplePaySession.STATUS_FAILURE,
                            [],
                            { label: 'Error', amount: '0' },
                            []
                        );
                    }
                });
        }

        /**
         * Handle Shipping Method Selection
         * Updates total based on selected shipping method
         * 
         * @param {ApplePaySession} session 
         * @param {Event} event 
         */
        handleShippingMethodSelection(session, event) {
            const method = event.shippingMethod;
            this.selectedShippingMethod = method;
            let newAmount = '0.00';

            if (this.currentSubtotalWithTax !== undefined) {
                newAmount = (this.currentSubtotalWithTax + parseFloat(method.amount)).toFixed(2);
            } else {
                // Fallback (might be inaccurate for multi-qty if initial logic wasn't fully synced)
                newAmount = (parseFloat(this.config.total) + parseFloat(method.amount)).toFixed(2);
            }

            const newTotal = {
                label: this.config.label,
                amount: newAmount
            };

            session.completeShippingMethodSelection(
                ApplePaySession.STATUS_SUCCESS,
                newTotal,
                []
            );
        }

        /**
         * Helper: Display error messages in the UI (Product Page)
         * 
         * @param {string} message 
         */
        showError(message) {
            if (!this.config.is_product) return;

            const $errorContainer = $(this.selectors.productError);
            $errorContainer.text(message).show();

            // Auto hide after 5 seconds
            setTimeout(() => $errorContainer.fadeOut(), 5000);
        }

        /**
         * Helper: Log debug messages properly
         */
        log(level, message, data = null) {
            if (this.config.debug) {
                const args = [`Moyasar Apple Pay: ${message}`];
                if (data) args.push(data);
                console[level](...args);
            }
        }

        /**
         * Helper: Base64 Encode API Key for Basic Auth
         * 
         * @param {string} key 
         * @returns {string}
         */
        encodeKey(key) {
            return btoa(key + ':');
        }
    }

    // Initialize the Payment Handler
    new MoyasarApplePay().init();

})(jQuery);
