# reactive.chat for WooCommerce

Live chat with an AI assistant for WooCommerce. Answers "Where is my order?" for signed-in customers – only ever with their own orders.

- Chat widget on every page of the shop, with position, colour scheme and an option to keep it off the checkout
- Signs the user ID of a signed-in customer; the identity secret never appears in the page
- Order questions answered from the shop, on request through a signed endpoint or as an encrypted import; every field from a fixed allow list, no names, addresses, e-mail addresses or payment data
- Consent built in: IAB TCF v2, consented.eu, the WP Consent API or a JavaScript signal
- On cart, checkout, My Account and order confirmation the assistant does not read the page text
- Compatible with HPOS and the Cart and Checkout blocks

Needs a reactive.chat account; order data in the chat needs a paid plan. Requires WordPress 6.5, PHP 7.4 and WooCommerce. Details in [readme.txt](readme.txt).

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
