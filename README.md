# ArionPay for WooCommerce

Take cryptocurrency payments in your WooCommerce shop with [ArionPay](https://arionpay.com).

Customers pay on a hosted ArionPay page. The money goes straight to your own wallets: ArionPay never holds it.

## What you need

- WordPress 6.5 or newer, PHP 7.4 or newer, WooCommerce 8.2 or newer
- An ArionPay account with one store, and a wallet connected to that store for real payments (test mode works without a wallet)
- Your shop on a public `https://` address on port 443 or 8443 (ArionPay does not send webhooks to other addresses)
- A shop currency ArionPay accepts: USD, EUR, GBP, CAD, AUD, CHF, JPY, TRY, AED, INR, BRL, RUB, CNY, SGD, HKD, KRW, MXN, PLN, SEK, NOK or ZAR

## Install

1. In WordPress go to **Plugins > Add New > Upload Plugin**.
2. Choose `arionpay-for-woocommerce.zip`, click **Install Now**, then **Activate**.

## Set up

1. In WordPress go to **WooCommerce > Settings > Payments > ArionPay (crypto)**.
2. In your ArionPay dashboard open your store and copy the **API key** (starts with `pk_`) into **Public key**, and the **API secret** (starts with `sk_`) into **Secret key**. ArionPay asks for your authenticator code before it shows the secret.
3. In your ArionPay dashboard open **Webhooks** and copy the **signing secret** (starts with `whsec_`) into **Webhook secret**.
4. Copy the **Webhook URL** shown at the bottom of the plugin settings. Paste it into the same **Webhooks** page in ArionPay and save. ArionPay asks for your authenticator code.
5. Tick **Enable ArionPay at checkout** and click **Save changes**.
6. Leave **API base URL** as it is. In your ArionPay store settings, make sure **Old ArionPay (v1) integration compatibility** is off: this plugin needs the current webhook format.

The secret key and webhook secret are never shown again after you save them. To change one, type the new value. To keep it, leave the box empty.

## Try it first (test mode)

1. Tick **Test mode** in the plugin settings and save.
2. Place an order in your shop and choose ArionPay.
3. On the ArionPay page, choose a coin and use the test payment buttons. No real money moves.
4. Check the order in WooCommerce: it should change to **Processing** and have a note saying it was a test.
5. You can also press **Send test webhook** on the ArionPay Webhooks page. A correct setup shows it as delivered. It does not change any order.

**Turn test mode off before you take real orders.**

## How orders change

| What happens in ArionPay | Order in WooCommerce |
| --- | --- |
| Invoice created | Stays **Pending payment** |
| `invoice.pending`: full amount seen, waiting for confirmations | **On hold** (do not ship yet) |
| `invoice.partial`: only part of the amount arrived, on an invoice with its own address | **On hold**, with a note (do not ship yet). On the normal fixed-address invoices a wrong amount does not send this: see Payment Radar below |
| `invoice.paid` | **Processing** (or **Completed** for orders that need no shipping) |
| `invoice.expired` | Stays unpaid, with a note. A late payment can still arrive |
| `invoice.needs_review` | No status change, a note with the reason. Check your ArionPay dashboard |

Every change is written as a note on the order. Notes say when a payment was late, and when it was accepted by hand in ArionPay.

If an order was cancelled and the payment arrives later, the order is reopened and a note explains why. If a refunded order gets paid, it is not changed and a note tells you.

## Wrong amounts and Payment Radar

Your wallet receives every payment at one fixed address per network, and each invoice has its own exact amount. **Only the exact amount is confirmed automatically.**

If a customer sends a different amount, the order stays unpaid. ArionPay's **Payment Radar** finds that payment and shows it in your ArionPay dashboard. You decide whether to accept it. If you accept it, ArionPay marks the invoice paid and this plugin updates the order.

The plugin itself has no tolerance setting and never decides whether an amount is close enough.

Today ArionPay's webhook only says the payment was accepted by hand. It does not say whether that was Payment Radar or "mark paid", so the order note says "Payment Radar or mark paid". The invoice in your dashboard shows which one it was.

## How the plugin decides an order is paid

Only two things can mark an order paid:

- a webhook from ArionPay with a correct signature, and
- this server reading the invoice from ArionPay with a signed request.

Coming back from the payment page does not mark an order paid by itself. If a webhook was missed, open the order and choose **Check ArionPay payment status** from the order actions.

## Good to know

- If a customer goes back and pays again, the plugin reuses the invoice that is still open. A new invoice is made only when the old one has expired or the order total has changed.
- The plugin works with WooCommerce's custom order tables (HPOS) and with both the classic checkout and the Checkout block.
- Refunds are made from your own wallet. The plugin does not send refunds.
- The plugin sends ArionPay the order total and currency, order number, the customer's email address, the return links for your shop, and an internal order reference.
- Problems are logged under **WooCommerce > Status > Logs** with the source `arionpay`. Keys and secrets are never logged.
- Deleting the plugin removes its settings. Notes on past orders stay.
