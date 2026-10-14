=== Moyasar ===
Contributors: moyasar
Tags: Gateway, Payment, Credit Card, Apple Pay, STC Pay
Requires at least: 5.8
Tested up to: 7.0
Stable tag: 8.4.1
Requires PHP: 7.4
Language: en
Translations: ar, ar_SA
URI: https://moyasar.com
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Moyasar Payment Gateway, Adds credit/debit card (Visa, MasterCard, Mada and American Express), Apple Pay, Samsung, and STC Pay payment capabilities to Woocommerce.

== Description ==

= Payments with Ease =
A comprehensive set of payment solutions that allows you to easily accept and track your transactions.

= Accept e-Payments in your WooCommerce store using Moyasar's plugin. =

== Features ==
- Credit Card (Visa, MasterCard, Mada, American Express, and UnionPay)
- Apple Pay (Web & Mobile)
- STC Pay
- Samsung Pay
- **NEW:** Payment Authorization & Manual Capture (authorize payments without immediate capture, with manual capture via WooCommerce order edit screen or Moyasar Dashboard webhook).
- **NEW:** Quick Buy (Beta) Button for instant product page checkout.
- **NEW:** Apple Pay Instant Checkout button on product page.
- **NEW:** Unified and Modern Admin Settings Dashboard.

== Supported Languages ==
- English (en_US)
- Arabic (ar)
- Arabic - Saudi Arabia (ar_SA)

To use the plugin in Arabic, set your WordPress site language to Arabic (ar) or Arabic - Saudi Arabia (ar_SA).


== Third-Party Services ==
This plugin relies on the following third-party services:
1. Moyasar Payment Gateway (Processing payments).
2. Apple Pay (Apple Pay SDK loaded for supported devices).
3. Samsung Pay (Samsung Pay SDK loaded for supported devices).
By using this plugin, you agree to the terms and conditions of these third-party services.

== Changelog ==

= 8.4.1 =
* Feature: Added "Validate API Keys" button in settings to verify Moyasar credentials.
* Fix: Removed extra spaces from API keys to prevent authentication errors.
* Improvement: Added key format validation to prevent saving wrong keys.

= 8.4.0 =
* Feature: Added Payment Authorization & Manual Capture setting ("manual") allowing merchants to authorize payments without immediate capture across Credit Card, Apple Pay, and Samsung Pay gateways.
* Feature: Added a dedicated "Capture" action button and inline capture panel on the WooCommerce order edit page next to the Refund button, supporting both full and custom amount captures.
* Feature: Added "Capture Payment via Moyasar" action in the WooCommerce order details actions dropdown.
* Feature: Registered a native "Authorized" order status (`wc-authorized`) in WooCommerce with customizable status transition mappings in admin settings.
* Feature: Added webhook event handlers for `payment_authorized` and `payment_captured` to synchronize order status changes initiated from the Moyasar Dashboard.

= 8.3.4 =
* Fix: Corrected the UnionPay network identifier for Apple Pay (now "chinaUnionPay"), resolving the "unionpay is not a valid payment network" error that blocked Apple Pay when UnionPay was enabled.
* Fix: Apple Pay on the classic checkout now sends valid network identifiers for every enabled brand (Mastercard and UnionPay were previously sent in an invalid format).
* Improvement: Samsung Pay now only requests the card networks it actually supports (Visa, Mastercard, Amex, Mada), preventing failures when other brands are enabled.
* Improvement: Added recognition of newer Mastercard cards in the 2-series (2221-2720) BIN range so they are detected and accepted at checkout.
* Improvement: American Express cards now use correct 15-digit card formatting and 4-digit security code entry.
* Improvement: Upgraded the Apple Pay session to version 6 to align with the latest Moyasar payment form.
* Change: American Express is no longer enabled by default under Allowed Brands (existing stores keep their current selection); enable it from the settings if needed.

= 8.3.3 =
* Fix: Extracted the encrypted token payload string (`3DS.data`) for Samsung Pay requests to prevent HTTP 500 error responses from the Moyasar API.
* Fix: Removed repetitive debug log calls from the WooCommerce Blocks availability check to eliminate log spamming during checkout.

= 8.3.2 =
* Fix: Resolved an issue where Samsung Pay and Apple Pay checkouts could fail with a "MALFORMED TOKEN" error during payment processing.
* Fix: Prevented multilingual plugins (WPML) from adding language subfolders to Webhook and Apple Pay verification URLs.

= 8.3.1 =
* Feature: Added support for customizing the border radius of Apple Pay buttons in WooCommerce Blocks checkout.
* Fix: Excluded WooCommerce Blocks checkout configuration, gateway localized inline scripts, and 3D Secure callback scripts from Cloudflare Rocket Loader (using data-cfasync="false") to prevent script load order issues and stuck checkouts.
* Fix: Corrected the STC Pay script handle to successfully apply Rocket Loader exclusions.
* Fix: Added automatic device/browser checks to hide Apple Pay on classic checkouts when unsupported.
* Fix: Resolved a legacy (non-HPOS) order storage issue with WP Swings Subscriptions checks where WooCommerce threw a `_doing_it_wrong` notice and misdetected regular orders as subscription orders.

= 8.3.0 =
* Feature: The embedded Credit Card form, Apple Pay, STC Pay, and Samsung Pay now work in the WooCommerce Block (Cart & Checkout blocks) checkout. Previously only the Invoice (redirect) option appeared in block-based checkouts — now every Moyasar payment method is available in both the classic and block checkout, including 3-D Secure verification for card payments and OTP verification for STC Pay.
* Improvement: Declared compatibility with the WooCommerce "Cart and Checkout Blocks" feature, so WooCommerce no longer flags the plugin as incompatible with block-based checkout.

= 8.2.4 =
* Fix: The store settings no longer show a false "Apple Pay is enabled but no valid Domain Verification File was found" warning when the domain association file is hosted correctly. The plugin now also detects the file when it is placed directly in the /.well-known/ directory or served by the web server outside WordPress, not just when uploaded through the plugin.

= 8.2.3 =
* Fix: On stores using the legacy (non-HPOS) order storage, webhook payment confirmations could be credited to the wrong order — WooCommerce silently ignored the payment-ID lookup and matched the newest order instead. Payments are now reliably matched to the correct order on both HPOS and legacy stores.
* Fix: The webhook now confirms the order using the authoritative order reference sent with the payment (validated against the order key), so payments land on the intended order even in edge cases, with a safe fallback for older payments.
* Fix: A promotion/coupon discount adjustment is now only applied when the payment actually carries a Moyasar coupon, preventing an incorrect negative fee from being added to orders in partial-payment, rounding, or misattributed-payment situations.

= 8.2.2 =
* Fix: Resolved a checkout "stuck on loading" issue after 3D Secure credit card verification — the payment window no longer times out and reloads while the customer is still completing verification (e.g. entering an SMS OTP or approving in their banking app).
* Fix: When a 3D Secure verification fails without a specific reason from the bank, the customer now sees a clear "Payment failed, please try again." message and can retry, instead of being left on a stuck loading screen.

= 8.2.1 =
* Feature: Added a "Recent Payments" section to the Moyasar dashboard tab that lists your latest payments — status, description, amount, payment method, and created/updated dates — pulled live from your Moyasar account, with a Test/Live mode indicator and clear messages when API keys are missing or invalid.

= 8.2.0 =
* Feature: Redesigned the Moyasar settings screen into clearer "General Settings" and "Advanced Settings" tabs.
* Feature: Added setup alerts on the settings page that warn you when API keys, the Apple Pay verification file, or the Samsung Pay Service ID are missing.
* Feature: Simplified Apple Pay Web Registration — upload the domain verification file directly and it is served automatically (and also saved to /.well-known/ on disk for hosts that serve that path statically).
* Improvement: Moyasar payment methods are now automatically hidden at checkout (both classic and block checkout) when no API keys are configured for the active Test/Live mode.
* Improvement: A clear notice is shown at checkout when online payments are unavailable because API keys are missing.

= 8.1.1 =
* Fix: Prevented a fatal error when a refund webhook is received, so refund and void status updates from the Moyasar dashboard are applied to the order reliably instead of failing and retrying.

= 8.1.0 =
* Feature: Added unified customization options for all payment methods (custom titles, descriptions, and options).
* Feature: Added Apple Pay and Samsung Pay checkout button styling settings (custom height, themes, labels, and border-radius).
* Feature: Added a toggle to disable "Enable Saved Cards" checkbox on the checkout UI while fully maintaining background subscription auto-renewals.
* Feature: Added custom gateway brand icons height adjustments with responsive legacy layout fallback when left empty.
* Fix: Solved WooCommerce drag-and-drop payment ordering glitches by dynamically aligning sub-gateways display sequence at checkout.
* Fix: Invoice title and description customizations now display consistently on the WooCommerce Blocks checkout.
* Improvement: Added a feedback banner in the settings dashboard with quick links to rate the plugin and reach support.

= 8.0.9 =
* Fix: Solved mobile layout squishing issues on the Credit Card form and fixed duplicated Apple/Samsung Pay buttons on responsive themes.

= 8.0.8 =
* Fix: Improved Apple Pay button click responsiveness and reliability on mobile devices.

= 8.0.7 =
* Feature: Add Arabic language support with translations for Arabic (ar) and Arabic - Saudi Arabia (ar_SA)
* Feature: Implement locale fallback mechanism for automatic translation discovery
* Improvement: Enhanced plugin internationalization (i18n) with locale override handling
* Improvement: Updated readme documentation to reflect language support

= 8.0.6 =
* Fix: Samsung Pay token sanitization now properly handles JSON structures, fixing "MALFORMED TOKEN" payment failures.
* Fix: Apple Pay instant checkout token and shipping data sanitization improved to prevent data corruption.

= v8.0.5 =
*   Fix: Resolved an issue where successful payments were sometimes incorrectly marked as failed in WooCommerce due to setting configuration conflicts or delayed notifications.
*   Fix: Improved order tracking and metadata synchronization for Credit Card payments.
*   Fix: Payment method enable/disable toggles now work correctly across all gateways.
*   Fix: Settings migration no longer wipes API keys and payment method settings on update for live-key-only installs.
*   Fix: Apple Pay testmode default corrected and admin style preview selector fixed.
*   Fix: Samsung Pay button rendering and environment (STAGE/PRODUCTION) corrected with service_id validation.
*   Fix: All payment methods prevent duplicate submissions by disabling the payment list during processing.
*   Improved: Mobile responsiveness for credit card inputs and saved payment method radio buttons.

= v8.0.4 =
*   Fix: Apple Pay merchant name (label) now validates for ASCII characters. If the store name contains non-ASCII characters (e.g. Arabic), the label falls back to the site domain prefixed with "WC-Store:".
*   Enhancement: Restored rich payment metadata across all gateways (CC, Apple Pay, Samsung Pay, STC Pay, subscriptions). Every payment now includes customer email, WC order key, customer ID, site URL, plugin/WP/WC versions, environment, billing/shipping addresses, and product details — matching the full metadata set from v7.

= v8.0.3 =
*   Improvement: Refactored Credit Card payment flow to trigger /payments creation directly from client-side.
*   Improvement: Enhanced order reuse on checkout payment retry to prevent duplicate pending orders.

= v8.0.2 =
*   Feature: Support ConversLabs/SpringDevs WPSubscription action hooks (subscrpt_subscription_payment_completed, subscrpt_subscription_payment_failed).
*   Improvement: Proactive payment token propagation directly to WC_Subscription objects for WooCommerce Subscriptions.
*   Improvement: Explicit registration of renewal hooks in gateway constructors.

= v8.0.1 =
*   Fix: Add scheduled subscription payments and card tokenization for WooCommerce Subscriptions in Credit Card and Apple Pay gateways.

= v8.0.0 =
*   **BREAKING CHANGE:** Completely redesigned Admin Settings Dashboard. All settings are now unified under "Moyasar Payments".
*   **Feature:** Added "Quick Buy (Beta)" button on product pages for instant checkout with credit cards.
*   **Feature:** Added "Instant Checkout" button on product pages for Apple Pay.
*   **Improved:** General UI/UX improvements across the payment forms.
*   **Feature:** Subscription for Card payment and Applepay
*   **Feature:** Save card option for future use



= v7.3.6 =
- Fixed: Explicitly import sprintf from wp.i18n

= v7.3.5 =
- Fixed: Duplicate issue when applying a coupon to an order.

= v7.3.4 =
- Fixed: Applepay gets the price from  WC()->cart->get_totals()['total'] function
- Feature: Add moyasar logos

= v7.3.3 =
- Fixed: trigger WC()->cart->calculate_totals when applying a coupon to ensure accurate totals

= v7.3.2 =
- Features: Apple Pay: Support Apple Pay on the web.
- Fixes: UI: Fixed credit card icons layout on small devices to prevent overflow/misalignment.

= v7.3.1 =
- Fixed: 3-D Secure modal watcher now handles stores that redirect `/checkout_mysr` to `/`. It no longer relies on an exact path match, preventing the modal from hanging.

= v7.3.0 =
- Feature: (Subscription) - Beta - Implemented Subscription function in Credit Card method. Always save the token in the database (after payment and in general).
- Fixed :(Coupon) - Reworked tryApplyCouponToOrder to add the product instead of previous behavior.

= v7.2.3 =
- Fixed: localization issues

= v7.2.2 =
- Fixed: (Blocks) Credit Card form not displaying correctly
- Fixed: submit button localization
- Improved: Samsung Pay logs

= v7.2.1 =
- Fixed: Missing Files

= v7.2.0 =
- Added: Support for Samsung Pay as a payment method.
- Added: Display of the payment amount and SAR currency on the payment button.
- Improved: General performance and stability enhancements.

= v7.1.15 =
- Fixed: Resolved an issue when applying a coupon code if the tax is included.

= v7.1.14 =
- Added: Compatibility with Cloudflare Rocket Loader by ignoring specific Apple Pay JavaScript.

= v7.1.13 =
- Fixed: Prevented multiple requests from being sent to fetch new order details in the Order class.
- Improved: Enabled direct redirection when 3D Secure (3DS) is not required.

= v7.1.12 =
- Fixed: Removed the unregisterForm method from the moyasarApplePayClassic class to address issues affecting Credit Card functionality.

= v7.1.11 =
- Added: Support for data-cfasync="false" attributes on script tags to ensure compatibility with Cloudflare Rocket Loader.

= v7.1.10 =
- General Fixes: Minor stability and compatibility improvements.

= v7.1.9 =
- Fixed: Resolved coupon code conflicts (Moyasar & Wordpress) that interfered with the checkout process.

= v7.1.8 =
- Fixed (i18n): Addressed STC Pay localization issues.

= v7.1.7 =
- Fixed: Prevented orders from being updated twice, ensuring accurate order management.

= v7.1.6 =
- Code Quality: Added more inline documentation for better developer understanding.

= v7.1.5 =
- Improvements: General enhancements for stability and performance.

= v7.1.4 =
- Fixed: Resolved conflicts with other plugins.

= v7.1.3 =
- General Fixes: Addressed various WordPress-specific issues and refinements.

= v7.1.2 =
- Fixed: Corrected JavaScript errors that occurred in certain checkout scenarios.

= v7.1.1 =
- Fixed: Addressed JavaScript issues affecting front-end interactions.
- Fixed: Resolved a refund-related issue for more reliable payment handling.

= v7.1.0 =
- Added: Popup modal for 3D Secure (3DS) in Classic mode, enhancing user experience during authentication.
- Fixed: Issues with creating the webhook secret key.
- Fixed: Card name placeholder now displays correctly.
- Fixed: Resolved conflicts with other plugins to maintain compatibility.
- Fixed: Addressed various Apple Pay popup issues to ensure a smoother checkout process.

= v7.0.0 =
- Feature: Implemented built-in payment forms for Credit Card, Apple Pay, and STC Pay.
- Compatibility: Introduced support for both Classic and Block editor environments.
- Blocks: Developed using React.js for a modern, dynamic user interface.
- Classic: Implemented native code for backward compatibility and performance.
