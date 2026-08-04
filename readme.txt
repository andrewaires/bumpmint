=== BumpMint - Order Bump for WooCommerce ===
Contributors: andrewaires
Tags: woocommerce, order bump, checkout, upsell, sales funnel
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 10.9
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create high-converting WooCommerce order bumps with flexible targeting, secure discounts, and multiple checkout positions.

== Description ==

### Turn every WooCommerce checkout into an opportunity to sell more

**BumpMint** helps you add relevant, one-click order bump offers directly to the WooCommerce checkout. Present the right complementary product at the moment customers are ready to buy and increase your store's average order value without adding extra steps to the purchase.

Create multiple order bumps, target each offer with flexible cart rules, include one or more offer products, choose exactly where they appear, and optionally add an exclusive fixed or percentage discount. Everything is managed from a clean, lightweight WordPress interface—no coding required.

### Why choose BumpMint?

* **Increase average order value** — Offer relevant add-ons before the customer completes the order.
* **Create a frictionless experience** — Customers accept an offer with one click, without leaving checkout.
* **Target offers more precisely** — Display each bump when any or all selected products are in the cart, or according to cart subtotal or item quantity.
* **Avoid redundant offers** — Optionally hide each offered product when that exact product or variation is already in the cart.
* **Protect promotional pricing** — Discounts and final prices are calculated and enforced on the server.
* **Limit discounted quantities** — Set how many units of each offered product can receive the order bump discount; the default is one.
* **Stay in control** — Customize the product, position, image, headline, description, and promotional banner.
* **Keep checkout fast** — BumpMint uses lightweight assets and loads them only where they are needed.

### Everything you can do with BumpMint

= Launch Multiple Order Bumps =

Create and manage multiple offers from one WordPress-native screen. Give every order bump an internal name so campaigns are easy to identify, edit, and organize.

= Offer Exclusive Discounts =

Make checkout offers more compelling with:

* Percentage discounts up to 100%.
* Fixed-value discounts.
* Each product's regular WooCommerce price when discounts are disabled.
* Automatic original and discounted price display.
* Automatic promotional banner text based on the configured discount.

Fixed discounts are capped so the final product price never becomes negative.

= Set Flexible Display Rules =

Choose when an order bump should appear:

* **Specific products in cart** — Trigger the offer when any or all selected products or variations are present.
* **Always show** — Display the offer on every eligible classic checkout.
* **Cart subtotal** — Show the offer when the subtotal is greater or less than a value you define.
* **Cart item quantity** — Show the offer when the number of product units is greater or less than a value you define.

Products added by BumpMint are excluded from subtotal and quantity conditions. This prevents an offer from activating or deactivating itself.

= Choose the Best Checkout Position =

Place each offer where it fits your checkout strategy:

* Before order items.
* After order items.
* Before payment methods.
* Before the **Place order** button.

These positions stay inside the WooCommerce classic checkout update flow, keeping offers synchronized when checkout totals refresh.

= Customize Every Offer =

Match the offer to your product and campaign:

* Add a custom promotional top banner.
* Use automatic **Sale**, percentage discount, or fixed discount text.
* Create a custom offer title and description.
* Insert dynamic `{product}` and `{price}` placeholders.
* Upload a custom image or automatically use the product image.
* Offer one or more simple products or specific purchasable variations as separately selectable offers.
* Optionally hide offered products that are already in the cart while leaving the rule's other products available.
* Display the original price and discounted price together.
* Deliver a responsive experience across desktop and mobile.

= Keep Prices and Cart Actions Secure =

The browser never decides which product price should be charged. It sends only the saved rule ID, the selected offer product ID, and the requested selection state.

BumpMint then verifies that the product belongs to the saved rule and validates the current cart condition, purchasability, stock, and discounted quantity on the server. The final price is recalculated from trusted WooCommerce data and enforced during cart total calculations.

### Start selling more in three simple steps

1. Create an order bump and choose one or more products you want to offer.
2. Select a display rule, checkout position, and optional discount.
3. Customize the banner and content, save the offer, and let customers add it with one click.

Install BumpMint and turn your existing WooCommerce checkout into a smarter revenue opportunity.

### Built for a clean WooCommerce workflow

The administration screen follows familiar WordPress patterns and shows:

* Actions.
* Internal order bump title.
* Display rule.
* Status.
* Regular or discounted price.
* Checkout position.
* Creation date.

### Compatibility

BumpMint uses WooCommerce classic checkout hooks and supports:

* The standard WooCommerce shortcode checkout.
* Compatible page-builder checkout widgets, including the native Elementor Pro Checkout widget.
* WooCommerce High-Performance Order Storage (HPOS).
* Simple products and specific purchasable variations.
* Any payment gateway that works normally with the classic WooCommerce checkout.

Version 1.0.0 does **not** support WooCommerce Cart and Checkout Blocks.

== Installation ==

1. In WordPress, go to **Plugins > Add New**.
2. Search for **BumpMint** and click **Install Now**.
3. Activate the plugin and make sure WooCommerce is active.
4. Open **Order Bumps** in the WordPress admin menu.
5. Click **Add New**, configure your first offer, and save it.

For manual installation, upload the `bumpmint-order-bump-for-woocommerce` folder to `/wp-content/plugins/` and activate it from the **Plugins** screen.

== Frequently Asked Questions ==

= Do I need to know how to code? =

No. Every order bump can be created and managed from the WordPress admin.

= Can I create more than one order bump? =

Yes. You can create and manage multiple order bumps. Every applicable offer assigned to a checkout position can be displayed.

= Can an order bump appear without a trigger product? =

Yes. Select **Always show** as the display rule.

= Can I select multiple trigger and offer products? =

Yes. A product rule can match any selected trigger product or require all selected trigger products to be in the cart. Each selected offer product is displayed separately so the customer can accept one or more offers.

= Can I limit how many discounted units a customer can buy? =

Yes. Each order bump has a maximum discounted quantity for every offered product. The default is one unit, and the limit is enforced on the server when the cart is updated and totals are calculated.

= Can I target the cart value or number of items? =

Yes. Use the **Cart subtotal** or **Cart item quantity** rule and choose **Greater than** or **Less than**.

= Does the subtotal include shipping and taxes? =

No. The cart subtotal condition excludes shipping and taxes. It also excludes products added by BumpMint.

= Does the quantity condition include the order bump itself? =

No. Products added through BumpMint are excluded from quantity conditions so an offer cannot trigger itself.

= Can I change the offer price? =

Yes. Enable the order bump discount and choose a fixed or percentage discount. Leave discounts disabled to use the product's normal WooCommerce price.

= What happens if a display condition stops matching? =

The special order bump price is no longer applied. The item returns to its canonical WooCommerce price, preventing an ineligible discount from remaining in the cart.

= Does BumpMint check product stock? =

Yes. Before an offered product is added, it must exist, be purchasable, be in stock, and have enough stock available.

= Can I customize the red promotional banner? =

Yes. Enter any custom banner text. If the field is empty, BumpMint displays **Sale** when no discount is configured, or the fixed/percentage discount value when a discount is enabled.

= Does BumpMint support product variations? =

Yes. A specific variation can be used as a trigger or offered product. A variable parent product must be configured as a specific purchasable variation when used as the offer.

= Where can I display the order bump? =

You can display it before or after the order items, before payment methods, or before the **Place order** button.

= Does BumpMint support WooCommerce Checkout Blocks? =

Not in version 1.0.0. BumpMint currently uses classic WooCommerce checkout hooks.

= Is BumpMint compatible with HPOS? =

Yes. BumpMint declares compatibility with WooCommerce High-Performance Order Storage.

= Where can I report a bug or contribute? =

Development is hosted on [GitHub](https://github.com/andrewaires/bumpmint). Use the repository's Issues section to report reproducible bugs or suggest improvements.

== Screenshots ==

1. A complete WooCommerce classic checkout with a targeted, discounted BumpMint offer before the Place order button.
2. Responsive order bump card with custom banner text, product image, original price, discounted price, and one-click selection.
3. Lightweight WordPress-native order bump list with actions, title, rule, status, price, position, and date.
4. Create an order bump with an internal name, trigger rule, trigger products, offered products, and checkout position.
5. Customize the offered products, secure discount, promotional banner, title, description, placeholders, and image.

== Changelog ==

= 1.0.0 - 2026-08-02 =
* Initial release.
