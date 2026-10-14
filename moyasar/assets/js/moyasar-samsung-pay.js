/* global moyasar_samsung_params, SamsungPay, jQuery */
(function ($) {
    'use strict';

    /**
     * Moyasar Samsung Pay Handler
     */
    class MoyasarSamsungPay {
        constructor() {
            this.config = moyasar_samsung_params;
            this.client = null;
            this.isProcessing = false;
            this.isAvailable = false;

            // DOM Selectors
            this.selectors = {
                checkoutContainer: '#moyasar-samsung-pay-button-container',
                errorContainer: '#moyasar-samsung-pay-error',
                placeOrderBtn: '#place_order, button[name="woocommerce_checkout_place_order"]',
                paymentRadio: 'input[name="payment_method"]',
                checkoutForm: 'form.checkout',
                tokenInput: '#moyasar_samsung_pay_token',
                quantityInput: 'input.qty, .quantity input'
            };
        }

        init() {
            if (typeof SamsungPay === 'undefined') {
                console.warn('Moyasar Samsung Pay: Samsung Pay SDK not loaded.');
                return;
            }

            // Initialize Client
            try {
                this.client = new SamsungPay.PaymentClient({
                    environment: this.config.environment
                });
            } catch (e) {
                console.error('Moyasar Samsung Pay: Failed to init client', e);
                return;
            }

            if (!this.config.service_id) {
                console.warn('Moyasar Samsung Pay: service_id is not configured.');
                return;
            }

            // Always render the button — let the SDK handle actual device availability at payment time
            this.isAvailable = true;
            this.renderButton();

            // Check availability in the background for debug logging only
            this.checkAvailability();

            // Bind global events for checkout toggling
            this.bindEvents();
        }

        /**
         * Samsung Pay Web SDK only accepts these brands:
         * https://developer.samsung.com/pay/web/api-reference.html
         * Filter the configured networks down to that set (lowercased) to match
         * the official payment form.
         */
        getAllowedBrands() {
            const SAMSUNG_PAY_NETWORKS = ['visa', 'mastercard', 'amex', 'mada'];
            return (this.config.supported_networks || [])
                .map((n) => n.toLowerCase())
                .filter((n) => SAMSUNG_PAY_NETWORKS.includes(n));
        }

        /**
         * Check if Samsung Pay is available using the SDK
         */
        checkAvailability() {
            const paymentMethods = {
                version: "2",
                serviceId: this.config.service_id,
                protocol: "PROTOCOL_3DS", // Standard for Web Checkout
                allowedBrands: this.getAllowedBrands()
            };

            this.client.isReadyToPay(paymentMethods)
                .then((response) => {
                    if (response.result) {
                        this.isAvailable = true;
                    } else {
                        console.warn('Moyasar Samsung Pay: Not available on this device/browser.');
                        if (this.config.debug) {
                            $(this.selectors.checkoutContainer).append('<p style="color:red;font-size:10px;">Debug: Button forced. SDK said not ready.</p>');
                        }
                    }
                })
                .catch((err) => {
                    console.error('Moyasar Samsung Pay: isReadyToPay Error:', err);
                    if (this.config.debug) {
                        $(this.selectors.checkoutContainer).append('<p style="color:red;font-size:10px;">Debug: SDK Error: ' + JSON.stringify(err) + '</p>');
                    }
                });
        }

        /**
         * Bind specific page events
         */
        bindEvents() {
            $('body').on('change', this.selectors.paymentRadio, () => this.toggleCheckoutButton());

            $(document.body).on('updated_checkout', () => {
                this.updateTotalFromDOM();
                if (this.isAvailable) {
                    this.renderButton();
                }
                this.toggleCheckoutButton();
            });

            $(document.body).on('checkout_error', () => {
                this.isProcessing = false;
                this.setPaymentOptionDisabled(false);
            });
        }

        setPaymentOptionDisabled(disabled) {
            $('ul.wc_payment_methods').toggleClass('moyasar-payment-processing', disabled);
        }

        updateTotalFromDOM() {
            const $el = $('.moyasar-update-total');
            if ($el.length) {
                const newTotal = $el.last().val();
                // Check if valid number
                if (newTotal && !isNaN(parseFloat(newTotal))) {
                    this.config.amount = newTotal;
                }
            }
        }

        /**
         * Render the Samsung Pay button
         */
        renderButton() {
            const $container = $(this.selectors.checkoutContainer);

            // Avoid duplicates
            if ($container.find('.moyasar-samsung-pay-btn').length > 0) {
                return;
            }

            // Samsung Pay Logo Base64
            const samsungLogo = "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAeEAAABLCAMAAABwZM8AAAADAFBMVEUAAAD////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////9/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f3+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7LtSl7AAAA/3RSTlMAAQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyAhIiMkJSYnKCkqKywtLi8wMTIzNDU2Nzg5Ojs8PT4/QEFCQ0RFRkdISUpLTE1O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";


            const borderRadius = (this.config.button_border_radius !== undefined && this.config.button_border_radius !== '') ? this.config.button_border_radius : '4';
            let heightPx = '44px';
            const heightSetting = this.config.button_height || 'medium';
            if (heightSetting === 'small') heightPx = '32px';
            if (heightSetting === 'large') heightPx = '55px';

            // Create button elements
            const $button = $('<div>', {
                class: 'moyasar-samsung-pay-btn',
                css: {
                    'background-color': 'black',
                    'color': 'white',
                    'border-radius': borderRadius + 'px',
                    'cursor': 'pointer',
                    'display': 'flex',
                    'justify-content': 'center',
                    'align-items': 'center',
                    'height': heightPx,
                    'font-size': '16px',
                    'margin-top': '10px'
                }
            });

            // "Pay with" Text
            const $span = $('<span>').text('Pay with');

            // Logo Image
            const $img = $('<img>', {
                class: 'mysr-samsung-pay-icon',
                src: samsungLogo,
                css: {
                    'height': '24px',
                    'vertical-align': 'middle',
                    'margin-left': '8px'
                }
            });

            $button.append($span).append($img);
            $button.on('click', (e) => this.handlePayment(e));

            // Append to container
            $container.append($button);

            // Run toggle check immediately
            this.toggleCheckoutButton();
        }

        /**
         * Toggle visibility and position of the Samsung Pay button on checkout
         * Replaces the default "Place Order" button when Samsung Pay is selected.
         */
        toggleCheckoutButton() {
            const selectedMethod = $(`${this.selectors.paymentRadio}:checked`).val();
            const $allPlaceOrderBtns = $(this.selectors.placeOrderBtn);
            const $samsungPayContainer = $(this.selectors.checkoutContainer);

            if (selectedMethod === 'moyasar_samsung_pay') {
                // Determine target button to append to (prefer visible button to handle responsive themes)
                let $targetBtn = $allPlaceOrderBtns.filter(':visible').first();
                if (!$targetBtn.length) {
                    $targetBtn = $allPlaceOrderBtns.first();
                }

                $allPlaceOrderBtns.hide();

                // Reposition container to the target place order button's parent if needed
                const $placeOrderParent = $targetBtn.parent();
                if ($placeOrderParent.length && $samsungPayContainer.parent()[0] !== $placeOrderParent[0]) {
                    $samsungPayContainer.detach().appendTo($placeOrderParent);
                }

                $samsungPayContainer.show();
            } else {
                // Do not show Place Order button if Apple Pay is selected, as it has its own button
                if (selectedMethod !== 'moyasar_apple_pay') {
                    $allPlaceOrderBtns.show();
                }
                $samsungPayContainer.hide();
            }
        }

        /**
         * Handle the button click
         */
        handlePayment(e) {
            e.preventDefault();
            if (this.isProcessing) return;

            let finalAmount = this.config.amount;
            if (this.config.is_product) {
                const qtyVal = $(this.selectors.quantityInput).val();
                const qty = qtyVal ? parseInt(qtyVal) : 1;
                // finalAmount string
                finalAmount = finalAmount * qty;
            }

            const transactionDetail = {
                orderNumber: 'ORDER-' + Date.now(),
                merchant: {
                    name: this.config.store_name,
                    url: window.location.hostname,
                    id: this.config.service_id,
                    countryCode: this.config.country
                },
                amount: {
                    option: "FORMAT_TOTAL_PRICE_ONLY",
                    currency: this.config.currency,
                    total: finalAmount
                }
            };

            const paymentMethods = {
                version: "2",
                serviceId: this.config.service_id,
                protocol: "PROTOCOL_3DS",
                allowedBrands: this.getAllowedBrands()
            };

            this.client.loadPaymentSheet(paymentMethods, transactionDetail)
                .then((paymentCredential) => {
                    this.processPayment(paymentCredential);
                })
                .catch((err) => {
                    console.error('Moyasar Samsung Pay: Payment Error:', err);
                    $(this.selectors.errorContainer).text('Payment failed or cancelled.').show();
                });
        }

        processPayment(paymentCredential) {
            // Put the token in the form
            $(this.selectors.tokenInput).val(JSON.stringify(paymentCredential));

            // Disable payment options to prevent re-submission during processing
            this.isProcessing = true;
            this.setPaymentOptionDisabled(true);
            $(this.selectors.checkoutForm).submit();
        }
    }

    // Initialize
    $(document).ready(() => {
        new MoyasarSamsungPay().init();
    });

})(jQuery);
