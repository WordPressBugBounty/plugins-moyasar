/* global moyasar_params, jQuery */
(function ($) {
    'use strict';

    /**
     * Moyasar Credit Card Form Handler
     * 
     * Handles Credit Card input formatting, validation, 3DS modal, and Quick Buy.
     * 
     * @class MoyasarCreditCard
     */
    class MoyasarCreditCard {

        constructor() {
            this.config = moyasar_params;
            this.madaBins = this.getMadaBins();

            // DOM Selectors
            this.selectors = {
                form: 'form.checkout, form#order_review, form#add_payment_method',
                ccNumber: '#moyasar_cc_number',
                ccExpiry: '#moyasar_cc_expiry',
                ccCvc: '#moyasar_cc_cvc',
                ccName: '#moyasar_cc_name',
                ccBrandImg: '#moyasar_card_brand_img',
                ccBrandPlaceholder: '#moyasar_card_brand_placeholder',
                tokenInput: '#moyasar_token',
                placeOrderBtn: '#place_order',
                quickBuyBtn: '#moyasar-instant-checkout-btn',
                quickBuyError: '#moyasar-instant-checkout-error'
            };
        }

        /**
         * Initialize the handler
         */
        init() {
            this.modalCompleted = false;
            this.$modal = null;
            this.bindEvents();
            this.setupHashHandler();
            this.setupQuickBuy();

            // Expose for the callback iframe to signal completion.
            window.moyasarComplete = (url, err) => this.handleIframeComplete(url, err);

            // Run initial checks (e.g. toggle form if saved card selected)
            this.toggleFormVisibility();
        }

        /**
         * Bind Form and Input Events
         */
        bindEvents() {
            const body = $(document.body);

            // Formatters
            body.on('input', this.selectors.ccNumber, (e) => this.formatCardNumber(e));
            body.on('input', this.selectors.ccExpiry, (e) => this.formatExpiry(e));
            body.on('input', this.selectors.ccCvc, (e) => this.formatCvc(e));

            // Checkout Error Handler
            body.on('checkout_error', () => this.clearToken());

            // Clean modal on error
            body.on('checkout_error', () => this.closeModal());

            // Re-enable payment option on error
            body.on('checkout_error', () => this.setPaymentOptionDisabled(false));

            // Submit Handler
            $('form.checkout').on('checkout_place_order_moyasar_cc', (e) => this.formHandler(e));
            $('form#order_review').on('submit', (e) => this.formHandler(e));
            $('form#add_payment_method').on('submit', (e) => this.formHandler(e));

            // Visibility Toggles
            body.on('updated_checkout change', () => this.toggleFormVisibility());
        }

        /**
         * Setup Quick Buy Button Handler
         */
        setupQuickBuy() {
            $(document).on('click', this.selectors.quickBuyBtn, (e) => this.handleQuickBuy(e));
        }

        /**
         * Handle Quick Buy Click
         * 
         * @param {Event} e 
         */
        handleQuickBuy(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const $error = $(this.selectors.quickBuyError);
            const originalText = $btn.text();

            $btn.addClass('loading').prop('disabled', true).text(this.config.processing_text || 'Processing...');
            $error.hide();

            const data = {
                action: 'moyasar_instant_checkout',
                security: $btn.data('security'),
                product_id: $btn.data('product_id'),
                token_id: $btn.data('token_id'),
                quantity: $('form.cart input[name="quantity"]').val() || 1
            };

            $.post(this.config.ajax_url, data)
                .done((response) => {
                    if (response.success) {
                        window.location = response.data.redirect;
                    } else {
                        $error.text(response.data.message || 'Error processing payment.').show();
                        setTimeout(() => $error.fadeOut(), 5000);
                        $btn.removeClass('loading').prop('disabled', false).text(originalText);
                    }
                })
                .fail(() => {
                    $error.text(this.config.strings.error_connection).show();
                    setTimeout(() => $error.fadeOut(), 5000);
                    $btn.removeClass('loading').prop('disabled', false).text(originalText);
                });
        }

        /**
         * Main Form Submission Handler
         * Tokenizes card data before submission
         * 
         * @param {Event} e 
         */
        formHandler(e) {
            // Check if Moyasar CC is selected
            if (!$('#payment_method_moyasar_cc').is(':checked') && $('form#add_payment_method').length === 0) {
                return true;
            }

            // Saved card: let the backend process the stored token.
            const tokenSelector = $('input[name="wc-moyasar_cc-payment-token"]:checked');
            if (tokenSelector.length > 0 && tokenSelector.val() !== 'new') {
                this.setPaymentOptionDisabled(true);
                return true;
            }

            // Validate Fields
            const cardData = this.getCardData();
            if (!this.validateCardData(cardData)) {
                e.preventDefault();
                return false;
            }

            const $form = $(e.target).closest('form');
            if ($form.is('#add_payment_method')) {
                e.preventDefault();
                $form.block({
                    message: null,
                    overlayCSS: { background: '#fff', opacity: 0.6 }
                });

                // Tokenize (only for Add Payment Method context)
                this.tokenizeCard(cardData, $form);
                return false;
            }

            // Disable payment option to prevent re-submission during processing
            this.setPaymentOptionDisabled(true);

            // Standard checkout: submit natively so the backend creates the order first.
            return true;
        }

        setPaymentOptionDisabled(disabled) {
            $('ul.wc_payment_methods').toggleClass('moyasar-payment-processing', disabled);
        }

        tokenizeCard(cardData, $form) {
            const payload = {
                name: cardData.name,
                number: cardData.number,
                cvc: cardData.cvc,
                month: cardData.month,
                year: cardData.year,
                save_only: !$form.is('#add_payment_method'),
                publishable_api_key: this.config.publishable_key,
                callback_url: window.location.href
            };

            fetch(this.config.token_api_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Basic ' + btoa(this.config.publishable_key + ':'),
                    'version': 'woo_' + this.config.plugin_version
                },
                body: JSON.stringify(payload)
            })
                .then(res => res.json())
                .then(data => this.handleTokenResponse(data, $form, cardData.name))
                .catch(err => {
                    console.error('Moyasar Connection Error:', err);
                    this.showError(this.config.strings.error_connection);
                });
        }

        handleTokenResponse(data, $form, cardName) {
            if (data.type === 'validation_error' || data.type === 'api_error' || data.message) {
                let errorMsg = data.message;
                if (data.errors) {
                    const keys = Object.keys(data.errors);
                    if (keys.length > 0) errorMsg += ': ' + data.errors[keys[0]][0];
                }
                this.showError(errorMsg);
                return;
            }

            if (data.id) {
                if (data.verification_url) {
                    this.openModal(data.verification_url, () => {
                        this.processQualifiedToken(data.id, cardName, $form);
                    });
                } else {
                    this.processQualifiedToken(data.id, cardName, $form);
                }
            } else {
                this.showError(this.config.strings.error_unknown);
            }
        }

        processQualifiedToken(tokenId, cardName, $form) {
            if ($form.is('#add_payment_method')) {
                this.verifyTokenBackend(tokenId, cardName);
                return;
            }

            $form.find(this.selectors.tokenInput).val(tokenId);

            const saveCard = $('#wc-moyasar_cc-new-payment-method').is(':checked') ? 'true' : 'false';
            if ($('#wc-moyasar_cc-new-payment-method-hidden').length === 0) {
                $form.append('<input type="hidden" id="wc-moyasar_cc-new-payment-method-hidden" name="wc-moyasar_cc-new-payment-method" value="' + saveCard + '">');
            } else {
                $('#wc-moyasar_cc-new-payment-method-hidden').val(saveCard);
            }

            $form.submit();
        }

        verifyTokenBackend(tokenId, name) {
            $.post(this.config.ajax_url, {
                action: 'moyasar_verify_token',
                nonce: this.config.verify_nonce,
                token: tokenId,
                name: name
            }, (res) => {
                if (res.success) {
                    if (res.data.verification_url) {
                        this.openModal(res.data.verification_url);
                    } else if (res.data.redirect_url) {
                        window.location.href = res.data.redirect_url;
                    } else {
                        window.location.reload();
                    }
                } else {
                    this.showError(res.data.message || this.config.strings.error_verification_failed);
                }
            }).fail(() => this.showError(this.config.strings.error_verification_request_failed));
        }

        getCardData() {
            const rawNumber = $(this.selectors.ccNumber).val().replace(/\s+/g, '');
            const exp = $(this.selectors.ccExpiry).val().split('/');

            return {
                name: $(this.selectors.ccName).val(),
                number: rawNumber,
                cvc: $(this.selectors.ccCvc).val(),
                month: exp[0] ? exp[0].trim() : '',
                year: exp[1] ? (exp[1].length === 2 ? '20' + exp[1].trim() : exp[1].trim()) : ''
            };
        }

        validateCardData(data) {
            if (!data.number || !data.name || !data.cvc || !data.month || !data.year) {
                this.showError(this.config.strings.error_incomplete_fields);
                return false;
            }

            const cardType = this.getCardType(data.number);
            const supported = this.config.supported_brands;
            let isSupported = false;

            if (Array.isArray(supported)) {
                if (supported.indexOf(cardType) !== -1) isSupported = true;
            } else if (typeof supported === 'object') {
                for (let key in supported) {
                    if (supported[key] === cardType) { isSupported = true; break; }
                }
            } else if (typeof supported === 'string') {
                if (supported === cardType) isSupported = true;
            }

            if (cardType !== 'unknown' && !isSupported) {
                this.showError(this.config.strings.error_card_type_unsupported.replace('%s', cardType.toUpperCase()));
                return false;
            }

            // Amex security code is 4 digits; all other networks use 3.
            const requiredCvcLength = cardType === 'amex' ? 4 : 3;
            if (data.cvc.length !== requiredCvcLength) {
                this.showError(this.config.strings.error_invalid_cvc);
                return false;
            }

            if (parseInt(data.month) > 12 || parseInt(data.month) < 1) {
                this.showError(this.config.strings.error_invalid_expiry);
                return false;
            }

            return true;
        }

        /**
         * Open the 3DS validation modal.
         *
         * @param {string}   url
         * @param {function} [onSuccess]     Called on same-origin return for non-callback flows.
         * @param {boolean}  [awaitCallback] True for payment flows that complete via moyasarComplete().
         */
        openModal(url, onSuccess = null, awaitCallback = false) {
            this.resetModalState();

            const modalHtml = `
                <div class="moyasar-modal-overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:99999; display:flex; justify-content:center; align-items:center;">
                    <div class="moyasar-modal-content" style="background:#fff; width:90%; max-width:600px; height:80%; position:relative; border-radius:8px; overflow:hidden; box-shadow:0 4px 15px rgba(0,0,0,0.2);">
                        <button type="button" class="moyasar-modal-close" style="position:absolute; top:10px; right:10px; z-index:100; background:none; border:none; font-size:24px; cursor:pointer;">&times;</button>
                        <iframe src="${url}" style="width:100%; height:100%; border:none;"></iframe>
                    </div>
                </div>`;

            const $overlay = $(modalHtml);
            $('body').append($overlay);
            this.$modal = $overlay;

            this.watchIframe($overlay, onSuccess, awaitCallback);

            $overlay.find('.moyasar-modal-close').on('click', () => {
                this.closeModal();
                this.showError(this.config.strings.error_payment_verification_cancelled);
                window.history.pushState('', document.title, window.location.pathname + window.location.search);
            });
        }

        /**
         * Tear down the modal. Idempotent — safe to call from any exit path.
         */
        closeModal() {
            $('.moyasar-modal-overlay').remove();
            this.$modal = null;
        }

        resetModalState() {
            this.closeModal();
            this.modalCompleted = false;
        }

        /**
         * Watch the 3DS iframe for its return to a same-origin URL.
         *
         * Listens for the iframe `load` event instead of polling location.href: the
         * callback endpoint blocks on server-side verification, so `load` fires only
         * after the full response (and its inline moyasarComplete() script) has run.
         * Polling used to see the same-origin "loading" URL first and tear the modal
         * down before that script executed.
         */
        watchIframe($overlay, onSuccess, awaitCallback) {
            const iframe = $overlay.find('iframe')[0];
            if (!iframe) {
                return;
            }

            // Fires on every navigation (cross-origin challenge, then the redirect
            // back to our callback), so the handler must stay attached across loads.
            const handleLoad = () => {
                // Modal already torn down (e.g. moyasarComplete() closed it): we're done
                // with this iframe, so detach before bailing out.
                if (!this.$modal || !document.body.contains(this.$modal[0])) {
                    iframe.removeEventListener('load', handleLoad);
                    return;
                }

                let href;
                try {
                    href = iframe.contentWindow.location.href;
                } catch (e) {
                    // Still on the cross-origin challenge; wait for the next load.
                    return;
                }

                if (!href || href === 'about:blank') {
                    return;
                }

                try {
                    if (new URL(href).origin !== window.location.origin) {
                        return;
                    }
                } catch (e) {
                    return;
                }

                // Same-origin return: this is the terminal load, so stop listening.
                iframe.removeEventListener('load', handleLoad);

                if (awaitCallback) {
                    // Payment flows complete via moyasarComplete(), which the callback page
                    // runs before this load fires. If it already ran we're done; otherwise
                    // the callback page loaded but never signalled — e.g. a PHP error page —
                    // so reload the checkout to recover instead of leaving the modal open.
                    if (this.modalCompleted) {
                        return;
                    }
                    this.closeModal();
                    window.location.reload();
                    return;
                }

                // Token verification flows return to a same-origin page that does not
                // emit moyasarComplete(), so complete them here.
                this.closeModal();
                if (typeof onSuccess === 'function') {
                    onSuccess();
                } else {
                    window.location.reload();
                }
            };

            iframe.addEventListener('load', handleLoad);
        }

        handleIframeComplete(resultUrl, errorMessage) {
            // Ignore duplicate signals so we never redirect or reset state twice.
            if (this.modalCompleted) {
                return;
            }
            this.modalCompleted = true;
            this.closeModal();

            // Contract: moyasar_callback always sends the order-received URL on success
            // and an empty URL on failure, so a URL here means the payment was accepted.
            if (resultUrl) {
                window.location.href = resultUrl;
                return;
            }

            // No redirect URL means the attempt failed. Always surface a message and
            // unblock (showError does both) so the shopper can retry — the callback can
            // emit moyasarComplete("", "") on a failed 3DS, which would otherwise leave
            // a blocked form with no feedback (the "stuck loading" symptom).
            this.clearToken();
            this.showError(errorMessage || this.config.strings.error_unknown);
        }

        formatCardNumber(e) {
            const input = $(e.target);
            const rawFull = input.val().replace(/\D/g, '');
            // Length by network: Amex is 15 digits, UnionPay up to 19, others 16.
            const isAmex = /^3[47]/.test(rawFull);
            const isUnionPay = /^(62|60|81)/.test(rawFull);
            const maxLength = isAmex ? 15 : (isUnionPay ? 19 : 16);
            const rawValue = rawFull.substring(0, maxLength);
            const cardType = this.getCardType(rawValue);
            let formatted = '';

            const iconUrl = this.config.icons_url[cardType];
            if (iconUrl) {
                $(this.selectors.ccBrandImg).attr('src', iconUrl).show();
                $(this.selectors.ccBrandPlaceholder).hide();
            } else {
                $(this.selectors.ccBrandImg).hide();
                $(this.selectors.ccBrandPlaceholder).show();
            }

            if (isAmex) {
                // Amex grouping: 4-6-5 (#### ###### #####), matching the payment form.
                const groups = [
                    rawValue.substring(0, 4),
                    rawValue.substring(4, 10),
                    rawValue.substring(10, 15)
                ];
                formatted = groups.filter((g) => g.length > 0).join(' ');
            } else {
                for (let i = 0; i < rawValue.length; i++) {
                    if (i > 0 && i % 4 === 0) formatted += ' ';
                    formatted += rawValue[i];
                }
            }
            input.val(formatted);
        }

        formatExpiry(e) {
            const input = $(e.target);
            let value = input.val().replace(/\D/g, '').substring(0, 4);

            if (value.length >= 2) {
                let month = value.substring(0, 2);
                let year = value.substring(2);
                if (parseInt(month) > 12) month = '12';
                input.val(month + ' / ' + year);

                if (value.length === 4) $(this.selectors.ccCvc).focus();
            } else {
                input.val(value);
            }
        }

        formatCvc(e) {
            const input = $(e.target);
            // Amex security code is 4 digits; all other networks use 3.
            const number = ($(this.selectors.ccNumber).val() || '').replace(/\D/g, '');
            const maxCvc = /^3[47]/.test(number) ? 4 : 3;
            input.val(input.val().replace(/\D/g, '').substring(0, maxCvc));
        }

        getCardType(number) {
            if (this.madaBins.some(bin => number.startsWith(bin))) return 'mada';
            if (/^4/.test(number)) return 'visa';
            // MasterCard: legacy 51-55 range plus the newer 2-series (2221-2720),
            // matching the official payment form's detection.
            if (/^(5[1-5]|222[1-9]|22[3-9]\d|2[3-6]\d\d|27[01]\d|2720)/.test(number)) return 'mastercard';
            if (/^3[47]/.test(number)) return 'amex';
            if (/^(58|63|96)/.test(number)) return 'mada';
            if (/^(62|60|81)/.test(number)) return 'unionpay';
            return 'unknown';
        }

        getMadaBins() {
            return [
                "22337902", "22337986", "22402030", "40177800", "403024", "40545400", "406136", "406996", "40719700", "40728100",
                "40739500", "407520", "409201", "410621", "410685", "410834", "412565", "417633", "419593", "420132", "421141",
                "42222200", "422817", "422818", "422819", "428331", "428671", "428672", "428673", "431361", "432328", "434107",
                "439954", "440533", "440647", "440795", "442429", "442463", "445564", "446393", "446404", "446672", "45488707",
                "45501701", "455036", "455708", "457865", "457997", "458456", "462220", "468540", "468541", "468542", "468543",
                "474491", "483010", "483011", "483012", "484783", "486094", "486095", "486096", "489318", "489319", "49098000",
                "49098001", "492464", "504300", "513213", "515079", "516138", "520058", "521076", "52166100", "524130", "524514",
                "524940", "529415", "529741", "530060", "531196", "535825", "535989", "536023", "537767", "53973776", "543085",
                "543357", "549760", "554180", "555610", "558563", "588845", "588848", "588850", "604906", "636120", "968201",
                "968202", "968203", "968204", "968205", "968206", "968207", "968208", "968209", "968211", "968212"
            ];
        }

        clearToken() {
            $(this.selectors.tokenInput).val('');
        }

        showError(message) {
            // Any error ends the current attempt — re-enable payment options for retry.
            this.setPaymentOptionDisabled(false);

            const form = $(this.selectors.form).first();
            $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();

            if (form.length) {
                $('html, body').animate({ scrollTop: (form.offset().top - 100) }, 500);
                form.prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><ul class="woocommerce-error" role="alert"><li>' + message + '</li></ul></div>');
                form.removeClass('processing').unblock();
            } else {
                alert(message);
            }
        }

        handleHashChange() {
            const hash = window.location.hash;
            if (hash.indexOf('#moyasar-verify:') === 0) {
                const url = hash.split('#moyasar-verify:')[1];
                if (url) this.openModal(url, null, true);
            } else if (hash.indexOf('#moyasar-pay-direct:') === 0) {
                this.handleDirectPaymentHash(hash);
            }
        }

        handleDirectPaymentHash(hash) {
            const parts = hash.split(':');
            if (parts.length < 5) return;
            const orderId = parts[1];
            const amount = parts[3];
            const callbackUrl = decodeURIComponent(parts[4]);
            const metadataStr = parts[5] ? decodeURIComponent(parts[5]) : '';
            let metadata = {};
            if (metadataStr) {
                try {
                    metadata = JSON.parse(metadataStr);
                } catch (e) {
                    console.error('Moyasar: Failed to parse metadata', e);
                }
            }

            // Clear the hash without reloading.
            window.history.pushState('', document.title, window.location.pathname + window.location.search);

            const cardData = this.getCardData();
            const $form = $('form.checkout, form#order_review').first();
            $form.block({
                message: null,
                overlayCSS: { background: '#fff', opacity: 0.6 }
            });

            // save_card is always true so Moyasar returns a token; the backend decides
            // whether to persist it based on the shopper's choice / subscription.
            const payload = {
                amount: parseInt(amount),
                currency: this.config.currency,
                description: 'Order #' + orderId,
                callback_url: callbackUrl,
                source: {
                    type: 'creditcard',
                    name: cardData.name,
                    number: cardData.number,
                    cvc: cardData.cvc,
                    month: cardData.month,
                    year: cardData.year,
                    save_card: true
                },
                metadata: metadata
            };

            if (this.config.manual) {
                payload.source.manual = true;
            }

            fetch(this.config.payment_api_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Basic ' + btoa(this.config.publishable_key + ':'),
                    'version': 'woo_' + this.config.plugin_version
                },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.type === 'validation_error' || data.type === 'api_error' || data.message) {
                    let errorMsg = data.message;
                    if (data.errors) {
                        const keys = Object.keys(data.errors);
                        if (keys.length > 0) errorMsg += ': ' + data.errors[keys[0]][0];
                    }
                    this.showError(errorMsg);
                    $form.unblock();
                    return;
                }

                if (data.id) {
                    if (data.status === 'initiated' && data.source && data.source.transaction_url) {
                        this.openModal(data.source.transaction_url, null, true);
                    } else if (data.status === 'paid') {
                        // Top-level navigation, not the modal iframe — use mode=redirect so
                        // the callback redirects instead of emitting a modal-only script.
                        const redirectCallback = callbackUrl.replace(/([?&])mode=modal/, '$1mode=redirect');
                        window.location.href = redirectCallback + '&id=' + data.id + '&status=' + data.status;
                    } else {
                        const errorMsg = (data.source && data.source.message) ? data.source.message : (data.message || this.config.strings.error_unknown);
                        this.showError(errorMsg);
                        $form.unblock();
                    }
                } else {
                    this.showError(this.config.strings.error_unknown);
                    $form.unblock();
                }
            })
            .catch(err => {
                console.error('Moyasar Direct Payment Error:', err);
                this.showError(this.config.strings.error_connection);
                $form.unblock();
            });
        }

        setupHashHandler() {
            $(window).on('hashchange', () => this.handleHashChange());
        }

        toggleFormVisibility() {
            const selected = $('input[name="wc-moyasar_cc-payment-token"]:checked');
            if (selected.length > 0 && selected.val() !== 'new') {
                $('.payment_method_moyasar_cc fieldset#wc-moyasar-cc-form').hide();
            } else {
                $('.payment_method_moyasar_cc fieldset#wc-moyasar-cc-form').show();
            }
        }
    }

    // Initialize
    new MoyasarCreditCard().init();

})(jQuery);
