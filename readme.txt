=== BumpMint - Order Bump for WooCommerce ===
Contributors: andrewaires
Tags: woocommerce, order bump, upsell, cross sell, checkout
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 10.9
Stable tag: 1.6.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Increase WooCommerce average order value with targeted order bumps and checkout upsells, secure discounts, scheduling, and analytics.

== Description ==

### Turn checkout intent into higher-value orders

**BumpMint is a lightweight WooCommerce order bump and checkout upsell plugin.** Present relevant add-ons while customers complete their purchase and let them accept standard products with one click.

Create multiple campaigns without code, target each offer to the current cart, add an exclusive discount, control when it runs, and see which bumps generate paid revenue. BumpMint works with WooCommerce classic checkout and Checkout Blocks, with native configuration for supported WooCommerce Bookings products.

### Why stores choose BumpMint

* **Sell more with less friction** — Add relevant products directly to checkout instead of sending customers through another funnel.
* **Show the right offer** — Target products, variations, categories, cart value, item quantity, or every eligible checkout.
* **Protect promotional margins** — Enforce discounts, stock, eligibility, and quantity limits on the server.
* **Run timely campaigns** — Schedule offers by date, weekday, and optional time range.
* **Match the store experience** — Customize the banner, image, title, description, and dynamic price or product text.
* **Know what converts** — Measure views, paid conversions, conversion rate, and net bump revenue locally.
* **Keep checkout lightweight** — Load focused assets only where BumpMint operates, with no external analytics service.

### Target the right cart at the right moment

Display an order bump when:

* Any or all selected products or variations are in the cart.
* A product belongs to a selected category, including child categories.
* Cart subtotal or item quantity is greater or less than a chosen value.
* Every eligible checkout should see the offer.

Product and category triggers can work together, and category-only campaigns are supported. Add a schedule for selected dates, recurring weekdays, or a time range in the WordPress site timezone. BumpMint excludes its own products from subtotal and quantity rules so an offer cannot activate itself.

### Create compelling offers without losing control

Offer one or more simple products, specific purchasable variations, or supported bookable products as independently selectable add-ons. You can:

* Apply a fixed or percentage discount.
* Limit how many units receive the discounted price.
* Hide an offered product when it is already in the cart.
* Show original and offer prices together.
* Use a custom promotional banner, image, title, and description.
* Insert dynamic `{product}` and `{price}` placeholders.

Prices are calculated from the effective WooCommerce price and enforced on the server. If a rule stops matching, BumpMint removes only the cart line created by that offer and preserves independently added products.

### Classic checkout, Checkout Blocks, and WooCommerce Bookings

Classic checkout supports four positions: before or after order items, before payment methods, or before the **Place order** button. Checkout Blocks display eligible offers below the order summary through WooCommerce's Store API integration.

Supported WooCommerce Bookings products use their native product form for dates, availability, resources, and people. After configuration, the customer returns to checkout and receives the bump discount on the booking price calculated by WooCommerce. Bookings that require confirmation use a separate purchase flow and are not offered.

### Built-in Analytics without external tracking

The **Order Bumps > Analytics** dashboard shows:

* Views and currently valid paid conversions.
* Conversion rate and net bump revenue.
* Top-performing order bump.
* Results by date, weekday, hour, campaign, and currency.

Conversions follow the current WooCommerce paid status, normally **Processing** or **Completed**. Cancellations, failed payments, and bump refunds update the report automatically. Revenue excludes taxes and shipping, and reporting starts only after Analytics is installed.

Analytics data stays in the WordPress database. BumpMint does not store customer names, email addresses, IP addresses, or user-agent strings in its report tables and does not send report data to third parties. Administrators can reset reports without deleting products, rules, or WooCommerce orders.

### Start in three steps

1. Create an order bump and choose the products to offer.
2. Select the targeting rule, schedule, checkout position, and optional discount.
3. Customize the presentation, save the campaign, and track its results.

### Compatibility

BumpMint supports:

* WooCommerce classic checkout and Checkout Blocks.
* Compatible checkout widgets that preserve WooCommerce hooks, including the native Elementor Pro Checkout widget.
* WooCommerce High-Performance Order Storage (HPOS).
* Simple products, specific purchasable variations, and WooCommerce Bookings products that do not require confirmation.
* Payment gateways compatible with the selected WooCommerce checkout type.

BumpMint displays offers during checkout and intentionally has no Cart Block offer interface.

== Installation ==

1. In WordPress, go to **Plugins > Add New**.
2. Search for **BumpMint** and click **Install Now**.
3. Activate the plugin and make sure WooCommerce is active.
4. Open **Order Bumps**, create your first campaign, and save it.

For manual installation, upload the `bumpmint-order-bump-for-woocommerce` folder to `/wp-content/plugins/` and activate it from the **Plugins** screen.

== Frequently Asked Questions ==

= Do I need to know how to code? =

No. Campaigns, rules, schedules, discounts, content, and reports are managed from the WordPress admin.

= Which conditions can trigger an order bump? =

Use any or all selected products, product categories, cart subtotal, cart item quantity, or **Always show**. Category-only rules are supported, and every condition can be combined with an availability schedule.

= Can one campaign contain multiple products or variations? =

Yes. Add one or more simple products or specific purchasable variations. Each offer is displayed separately so customers can accept one or more products.

= Can I schedule an order bump? =

Yes. Run it every day, on selected calendar dates, or on recurring weekdays. An optional time range uses the WordPress site timezone and can continue past midnight.

= Does BumpMint support classic checkout and Checkout Blocks? =

Yes. Classic checkout provides four configurable positions. Checkout Blocks display eligible offers below the order summary. BumpMint does not display offers on the cart page.

= Does BumpMint support WooCommerce Bookings? =

Yes, for bookable products that do not require confirmation. Customers use the native product form to choose required options and then return to checkout with the BumpMint discount applied to the calculated booking price.

= How are discounts and quantity limits protected? =

BumpMint validates the saved campaign, current cart, product, stock, price, and quantity on the server. Fixed and percentage discounts use the effective WooCommerce price, and the default discounted quantity limit is one unit.

= What does Analytics count? =

Analytics reports bump views, currently valid paid conversions, conversion rate, and net product revenue after applicable refunds. Taxes, shipping, and orders created before tracking began are excluded. Report data stays in WordPress and can be reset by an administrator.

= Is BumpMint compatible with HPOS? =

Yes. BumpMint declares compatibility with WooCommerce High-Performance Order Storage.

= Where can I get support or report a bug? =

Use the [WordPress.org support forum](https://wordpress.org/support/plugin/bumpmint-order-bump-for-woocommerce/) or report reproducible issues on [GitHub](https://github.com/andrewaires/bumpmint/issues).

== Screenshots ==

1. A targeted fixed-discount order bump selected in WooCommerce classic checkout.
2. Eligible order bump offers displayed below the order summary in WooCommerce Checkout Blocks.
3. Multiple independently selectable order bump products in classic checkout.
4. Local Analytics overview with views, paid conversions, conversion rate, net revenue, top bump, and performance over time.
5. Analytics breakdown by weekday, hour, and individual order bump.
6. WordPress-native campaign management with rules, schedules, status, price, and checkout position.
7. Create a product- or category-triggered order bump and choose its availability.
8. Configure scheduling, offered products, classic checkout position, cart behavior, and discounts.
9. Customize banner text, title, description, dynamic placeholders, and product image.

== Changelog ==

= 1.6.1 - 2026-08-18 =
* Fixed WooCommerce price entities appearing as encoded text in Checkout Block offer badges, titles, and descriptions.
* Refreshed the WordPress.org and GitHub presentation with concise documentation and current screenshots.

= 1.6.0 - 2026-08-18 =
* Added native WooCommerce Bookings offers using the product's date and options form before returning customers to checkout.
* Applied discounts to the booking cost calculated by WooCommerce and kept configured bookings removable in classic checkout and Checkout Blocks.
* Added signed, session-bound handoffs with server-side validation.
* Verified compatibility with WordPress 7.1.

= 1.5.0 - 2026-08-11 =
* Added local Analytics with period, order bump, and currency filters.
* Added views, paid conversions, conversion rate, net bump revenue, top-performing bump, and date, weekday, and hourly breakdowns.
* Synchronized reports with current WooCommerce paid statuses and BumpMint line refunds while excluding taxes and shipping.

See the [complete changelog](https://github.com/andrewaires/bumpmint/blob/main/changelog.txt) for earlier releases.
