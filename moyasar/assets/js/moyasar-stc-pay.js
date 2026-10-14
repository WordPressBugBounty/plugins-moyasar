/* global moyasar_stc_params, jQuery */
(function ($) {
    'use strict';

    /**
     * Moyasar STC Pay Handler
     * 
     * Handles STC Pay Mobile Number validation, AJAX payment initiation,
     * OTP Modal display, and confirmation.
     */
    class MoyasarSTCPay {
        constructor() {
            this.config = moyasar_stc_params;
            this.isProcessing = false;
        }

        init() {
            this.bindEvents();
            this.setupHashHandler();
        }

        bindEvents() {
            // Only validate mobile on submit, let form submit naturally
            $('form.checkout').on('checkout_place_order_moyasar_stc_pay', (e) => this.handleCheckout(e));

            // Reset processing state on checkout error
            $(document.body).on('checkout_error', () => {
                this.isProcessing = false;
                this.setPaymentOptionDisabled(false);
            });
        }

        handleCheckout(e) {
            if (this.isProcessing) {
                return false;
            }

            let mobile = $('#moyasar_stc_pay_mobile').val().trim();

            // Remove spaces and dashes
            mobile = mobile.replace(/[\s-]/g, '');

            // Convert different formats to 05xxxxxxxxx
            if (mobile.startsWith('+966')) {
                mobile = '0' + mobile.slice(4);
            } else if (mobile.startsWith('00966')) {
                mobile = '0' + mobile.slice(5);
            } else if (mobile.startsWith('966')) {
                mobile = '0' + mobile.slice(3);
            } else if (mobile.startsWith('5')) {
                mobile = '0' + mobile;
            }

            // Final validation (must be 05 + 8 digits)
            if (!/^05\d{8}$/.test(mobile)) {
                this.showError(this.config.strings.error_invalid_mobile);
                return false;
            }

            // Update input with normalized value
            $('#moyasar_stc_pay_mobile').val(mobile);

            this.isProcessing = true;
            this.setPaymentOptionDisabled(true);
            return true;
        }

        setPaymentOptionDisabled(disabled) {
            $('ul.wc_payment_methods').toggleClass('moyasar-payment-processing', disabled);
        }

        setupHashHandler() {
            $(window).on('hashchange', () => this.handleHashChange());
        }

        handleHashChange() {
            const hash = window.location.hash;
            if (hash.indexOf('#moyasar-stc-verify:') === 0) {
                const parts = hash.split(':');
                if (parts.length >= 3) {
                    const paymentId = parts[1];
                    const transactionUrl = atob(parts[2]);
                    this.showOtpModal(paymentId, transactionUrl);
                }
            }
        }

        showOtpModal(paymentId, transactionUrl) {
            $('.moyasar-modal-overlay').remove();

            const modalHtml = `
                <div class="moyasar-modal-overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:99999; display:flex; justify-content:center; align-items:center;">
                    <div class="moyasar-modal-content" style="background:#fff; width:90%; max-width:400px; padding:25px; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.2); position:relative; text-align:center;">
                        <button type="button" class="moyasar-modal-close" style="position:absolute; top:10px; right:15px; background:none; border:none; font-size:24px; cursor:pointer; color:#999;">&times;</button>
                        
                        <h3 style="margin-top:0; color:#333;">${this.config.strings.stc_pay_verification}</h3>
                        <p style="color:#666; font-size:14px; margin-bottom:20px;">${this.config.strings.enter_otp_message}</p>
                        
                        <div style="margin-bottom:20px;">
                            <input type="text" id="moyasar_stc_otp" placeholder="${this.config.strings.enter_otp_placeholder}" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px; font-size:16px; text-align:center; letter-spacing: 2px;" autocomplete="one-time-code">
                        </div>

                        <button type="button" id="moyasar_stc_confirm_btn" style="width:100%; padding:12px; background:#4f2c85; color:#fff; border:none; border-radius:4px; font-size:16px; cursor:pointer; transition: background 0.2s;">${this.config.strings.confirm_payment_btn}</button>
                        
                        <div id="moyasar_stc_error" style="color:red; font-size:13px; margin-top:10px; display:none;"></div>
                    </div>
                </div>`;

            $('body').append(modalHtml);
            $('#moyasar_stc_otp').focus();

            $('.moyasar-modal-close').on('click', () => {
                $('.moyasar-modal-overlay').remove();
                // Clear hash so it can be triggered again if needed or prevent back button issues
                window.history.pushState('', document.title, window.location.pathname + window.location.search);
                this.isProcessing = false;
                this.showError(this.config.strings.verification_cancelled);
            });

            $('#moyasar_stc_confirm_btn').on('click', () => this.confirmPayment(paymentId, transactionUrl));

            $('#moyasar_stc_otp').on('keypress', (e) => {
                if (e.which === 13) this.confirmPayment(paymentId, transactionUrl);
            });
        }

        confirmPayment(paymentId, transactionUrl) {
            const otp = $('#moyasar_stc_otp').val();
            const $btn = $('#moyasar_stc_confirm_btn');
            const $error = $('#moyasar_stc_error');

            if (!otp) {
                $error.text(this.config.strings.otp_required).show();
                return;
            }

            $btn.prop('disabled', true).text(this.config.strings.processing_payment);
            $error.hide();

            $.post(this.config.ajax_url, {
                action: 'moyasar_stc_pay_confirm',
                nonce: this.config.confirm_nonce,
                payment_id: paymentId,
                transaction_url: transactionUrl,
                otp: otp
            })
                .done((response) => {
                    if (response.success && response.data.redirect_url) {
                        window.location.href = response.data.redirect_url;
                    } else {
                        $error.text(response.data.message || this.config.strings.verification_failed).show();
                        $btn.prop('disabled', false).text(this.config.strings.confirm_payment_btn);
                        this.isProcessing = false;
                    }
                })
                .fail(() => {
                    this.showError(this.config.strings.error_payment_failed);
                    $btn.prop('disabled', false).text(this.config.strings.confirm_payment_btn);
                    this.isProcessing = false;
                });
        }

        showError(message) {
            const form = $('form.checkout');
            $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
            $('html, body').animate({ scrollTop: (form.offset().top - 100) }, 500);
            form.prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><ul class="woocommerce-error" role="alert"><li>' + message + '</li></ul></div>');
            form.removeClass('processing').unblock();
        }
    }

    // Initialize
    new MoyasarSTCPay().init();

})(jQuery);
