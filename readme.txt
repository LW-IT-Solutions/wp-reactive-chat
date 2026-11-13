=== reactive.chat for WooCommerce ===
Contributors: lwitsolutions
Tags: live chat, ai chatbot, woocommerce, customer support, order status
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Live chat with an AI assistant for WooCommerce. Answers "Where is my order?" for signed-in customers - only ever with their own orders.

== Description ==

reactive.chat is a live chat with an AI assistant and human agents. This plugin connects it to your WooCommerce shop:

* **Chat widget in your shop.** Paste the site key of your reactive.chat project and the chat appears on every page. Position, colour scheme, a hidden chat button for your own links and an option to keep the chat off the checkout page are set right here.
* **Recognises signed-in customers.** With the identity secret of your project, the plugin signs the WordPress user ID of a signed-in customer on every page view (valid for one hour). The secret never appears in the page.
* **Order questions answered from your shop.** "Where is my parcel?", "Has my invoice been paid?", "What was in my last order?" - the assistant answers with the orders of the signed-in customer who is asking, and with nobody else's.
* **You decide every field.** Order number, date, status, total, product names and quantities, carrier, tracking number, shipping status, invoice number and payment status. City and postcode only if you switch them on. Names, street addresses, e-mail addresses, phone numbers, payment data, customer notes and line item meta never leave the shop.
* **Consent built in.** Either the widget asks your consent platform itself (IAB TCF v2 or consented.eu), or the plugin loads it only after consent - through the WP Consent API, an IAB TCF v2 platform or a JavaScript signal of your site.
* **Private pages stay private.** On the cart, checkout, My Account and order confirmation pages the assistant does not read the page text.
* **Status and tests.** The settings page shows what works and what is missing, tests the customer data endpoint with a signed request and checks the API key.

= Two ways to get order data into the chat =

* **On request (recommended):** reactive.chat calls a signed endpoint of your shop for each question. Nothing is stored at reactive.chat.
* **Import:** your shop pushes the latest ten orders of a customer to reactive.chat whenever an order changes, in the background through the WooCommerce Action Scheduler. Stored encrypted there and deleted after 90 days at the latest.

Order data in the chat requires a paid plan at reactive.chat. The chat widget itself works with every plan.

= How order data is protected =

* reactive.chat asks the endpoint only for a visitor whose customer ID this shop has signed (HMAC with the identity secret, with an expiry time).
* Every request to the endpoint is signed with the endpoint secret, valid for five minutes and accepted only once. Only the signed request body is read; URL parameters and a logged-in WordPress session play no role.
* Orders are read with `wc_get_orders()` for exactly that customer and checked once more against the customer ID. Guest orders are never returned. An order number from the chat is only a filter within the customer's own orders.
* Every answer is built field by field from a fixed list (allow list). Whatever other plugins store in order meta cannot slip through.

= For developers =

* `reactive_chat_load_widget` (bool) - load the widget on this request? For example to follow a server-side consent decision.
* `reactive_chat_is_private_page` (bool) - pages on which the assistant must not read the page text.
* `reactive_chat_loader_attributes` (array) - further `data-rc-*` attributes for the loader tag (see the script tag reference).
* `reactive_chat_order_raw` (array, WC_Order) - add data of a tracking or invoice plugin; the result still runs through the allow list.
* `reactive_chat_ident_ttl` (int) - lifetime of the signed identity in seconds (default 3600, at most 86400).
* `reactive_chat_ident_email` (bool) - also sign the customer's e-mail address (needed for appointment booking). Off by default.
* JavaScript: `window.reactiveChatConsent( true )` or `( false )` in the consent mode "Only after consent". A withdrawal closes the chat on the current page; renewed consent applies from the next page view.

Supported out of the box: WooCommerce Shipment Tracking, Advanced Shipment Tracking, Germanized (shipments, invoices), PDF Invoices & Packing Slips for WooCommerce. Compatible with High-Performance Order Storage (HPOS) and the Cart and Checkout blocks.

== External services ==

This plugin connects your shop to **reactive.chat**, a chat service operated by LW IT Solutions, Lukas Wójcik. Without a site key the plugin sends nothing anywhere.

**1. Chat widget (visitors' browsers)**

* What: the widget script `loader.js` and the chat core are loaded from `https://cdn.reactive.chat`. The chat itself runs over a WebSocket connection to `wss://ws.reactive.chat` (fallback over HTTPS to `https://ws.reactive.chat` when a network blocks WebSockets). If you set your own addresses under Advanced, those are used instead.
* When: on every page of the shop once a site key is set. In the consent mode "Only after consent" only after the visitor has consented. Not on the checkout page if you choose so.
* Data: the visitor's IP address and browser data (as with every web request), the site key, the address of the page, messages the visitor writes, and - unless switched off - the visible text of the page so the assistant can answer questions about it (never on cart, checkout, My Account and order confirmation pages if "Private pages" is on). For signed-in customers and only with an identity secret: the WordPress user ID with a signature and an expiry time; the e-mail address only if a developer switches it on with the filter `reactive_chat_ident_email`.

**2. Customer data endpoint (reactive.chat servers -> your shop)**

* What: reactive.chat sends signed POST requests to `/wp-json/reactive-chat/v1/kundendaten` of your shop.
* When: only in the modes "On request" or "Both", when a confirmed customer asks about orders, and when you press the test button in your reactive.chat project.
* Data sent back to reactive.chat: the fields you switched on for the orders of that one customer (see above).

**3. Import API (your shop -> reactive.chat)**

* What: `PUT` and `DELETE` requests to `https://reactive.chat/v1/kundendaten/{customer ID}` with your API key; `GET https://reactive.chat/v1/me` when you press "Check API key".
* When: only in the modes "Import" or "Both": after an order is created, changed, trashed or deleted; when you press "Transfer all customers now"; when a user is deleted or a personal data erasure request is processed (DELETE, as long as an API key is saved).
* Data: the WordPress user ID and the switched-on fields of that customer's latest ten orders. The key check sends only the API key.

Service links: [Terms of Service](https://reactive.chat/en/p/terms-of-service), [Privacy Policy](https://reactive.chat/en/p/privacy-policy), [Data Processing Agreement](https://reactive.chat/en/p/data-processing-agreement), [Imprint](https://reactive.chat/en/p/imprint).

== Installation ==

1. Install and activate WooCommerce and this plugin.
2. Create a project for your shop in your [reactive.chat account](https://reactive.chat/en/app) and copy its site key (Project > Installation).
3. Go to **WooCommerce > reactive.chat**, paste the site key and save. The chat now appears in your shop.
4. Optional - signed-in customers: create an identity secret in your project (Project > Identity) and paste it into "Identity secret".
5. Optional - order data: choose the mode "On request", copy the endpoint address into your project (Project > Customer data), create the endpoint secret there and paste it here. Press "Test endpoint", then the test button in your project.
6. Choose how consent is handled under "Privacy and consent" and add the suggested text from Settings > Privacy > Policy guide to your privacy policy.

The customer data endpoint must be reachable via https on port 443 without redirects.

== Frequently Asked Questions ==

= Do I need a reactive.chat account? =

Yes. The plugin connects your shop to the reactive.chat service. The chat widget works with every plan; order data in the chat needs a paid plan.

= Can a visitor see someone else's order by typing its number? =

No. Access is decided only by the identity your shop has signed. A number from the chat is only a filter within the orders of the signed-in customer; someone else's number finds nothing. Guests never get order data.

= Which order data reaches the assistant? =

Only the fields switched on both in this plugin and in your reactive.chat project. Names, street addresses, e-mail addresses, phone numbers, bank and card data, dates of birth, passwords and tax or ID numbers can never be switched on.

= Does the plugin store visitor data in WordPress? =

No. It stores its settings, the secrets you paste (in options that are not loaded on every page view), the result of the last transfer and test, and the IDs of recently processed requests for ten minutes (replay protection).

= How do I load the chat only after consent? =

Under "Privacy and consent" choose "Only after consent" and the consent source: the WP Consent API (supported by many consent plugins), an IAB TCF v2 platform (purpose 1), or your own JavaScript signal `window.reactiveChatConsent( true )`. Until then no request goes to reactive.chat.

= The endpoint test fails. What now? =

The message says why: the address redirects (check https and www in Settings > General), a security plugin or proxy strips the X-RC-Zeit and X-RC-Signatur headers, or the REST API is disabled. If only the loopback request fails, your host may block requests to itself; use the test button in your reactive.chat project.

= Does it work with HPOS and the block checkout? =

Yes. Orders are read only through the WooCommerce order API, and the plugin adds nothing to cart or checkout.

= What happens to imported data when I uninstall the plugin? =

The plugin removes its own settings and pending jobs. Imported order data at reactive.chat expires after 90 days at the latest; you can empty it at once in your reactive.chat project.

== Screenshots ==

1. Status overview with endpoint test and API key check.
2. Chat widget settings and consent options.
3. Order data: mode, endpoint address and field selection.
4. The chat widget in the shop.

== Changelog ==

= 1.0.0 =
* First public release: chat widget, signed identity of signed-in customers, signed customer data endpoint, import through the Action Scheduler, field selection, consent modes (WP Consent API, IAB TCF v2, JavaScript signal), private pages, status overview with endpoint test and API key check, privacy policy text, personal data eraser, HPOS and Cart/Checkout blocks compatibility.

== Upgrade Notice ==

= 1.0.0 =
First public release.
