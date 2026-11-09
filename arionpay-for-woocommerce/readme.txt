=== ArionPay for WooCommerce ===
Contributors: arionpay
Tags: woocommerce, crypto, bitcoin, payment gateway, usdt
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.2
WC tested up to: 11.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept cryptocurrency in WooCommerce through ArionPay, a non-custodial payment gateway.

== Description ==

Customers choose ArionPay at checkout and pay on a hosted ArionPay page. The money goes straight to your own wallets. ArionPay never holds it.

* Works with the classic checkout and the Checkout block
* Works with WooCommerce custom order tables (HPOS)
* Orders are updated by signed webhooks, with a manual "Check ArionPay payment status" order action as a backup
* Reuses an open invoice if the customer retries
* Test mode with no real money

Only the exact amount shown on the invoice is confirmed automatically. A different amount is found by Payment Radar in your ArionPay dashboard, where you decide whether to accept it. The plugin has no tolerance setting of its own.

= External service =

This plugin connects to the ArionPay service to create and read invoices (by default at https://merchant.arionpay.com). It sends the order total and currency, order number, the customer's email address, return links for your shop and an internal order reference. It receives invoice status updates by webhook at your shop.

Service: https://arionpay.com

== Installation ==

1. Upload and activate the plugin. WooCommerce must be active.
2. Go to WooCommerce > Settings > Payments > ArionPay (crypto).
3. Paste your public key, secret key and webhook secret from your ArionPay dashboard.
4. Copy the Webhook URL from the plugin settings into your store's Webhooks page in ArionPay.
5. Enable the method and save. Use Test mode first if you want to try it without real money.

Your shop must be on a public https address on port 443 or 8443 to receive webhooks. If your store has old API (v1) compatibility turned on in ArionPay, turn it off.

== Frequently Asked Questions ==

= A customer sent the wrong amount. What happens? =

The order stays unpaid. Payment Radar shows the payment in your ArionPay dashboard. If you accept it there, the invoice is paid and the order is updated.

= Does coming back from the payment page mark an order paid? =

No. Only a webhook with a valid signature, or a signed invoice read by your server, can mark an order paid.

= Does the plugin send refunds? =

No. Refunds are made from your own wallet.

== Changelog ==

= 1.0.0 =
* First release.
