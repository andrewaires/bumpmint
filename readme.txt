=== BumpMint - Order Bump for WooCommerce ===
Contributors: andrewaires
Tags: woocommerce, order bump, upsell, cross sell, checkout
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 10.9
Stable tag: 1.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Increase WooCommerce average order value with checkout order bumps and upsells. Offer custom discounts and display scheduled offers.

== Description ==

### Maximize every sale! Turn purchase intent into higher-value orders.

**BumpMint is a lightweight plugin for creating Order Bumps and Upsells that help WooCommerce stores increase average order value**. Display elegant, relevant offers while customers complete their purchase and let them add more products to their order with just one click.

Easily create multiple campaigns and target each offer based on the current cart, apply an exclusive discount, control when it is available, and discover which order bumps generate more sales for your store.

**Build a more strategic checkout** — Getting a new customer all the way to checkout takes effort, so BumpMint helps your store make the most of that moment by displaying relevant offers that can increase order value without adding another step to the buying process.

### Why stores choose BumpMint

* **Sell more with one click** — Add relevant products directly at checkout without sending customers through another funnel.
* **Set your store apart from other WooCommerce stores** — Turn the standard checkout into a distinctive experience with relevant, personalized offers at the moment of purchase.
* **Tired of paying for premium features?** — Use all of our advanced features without a premium plan or any extra cost. Get a complete feature set entirely free to help your store sell more.
* **Show the right offer** — Target products, variations, categories, cart value, item quantity, customer audiences, locations, or applied coupons.
* **Run campaigns at the right time** — Schedule offers by date, weekday, and time range.
* **Stay true to your brand** — Customize the banner, image, title, description, and dynamic price or product text.
* **Know what converts** — Track views, paid conversions, conversion rate, and net order bump revenue in an Analytics dashboard.
* **Extra security for your orders** — Discounts, stock, eligibility, and quantity limits are securely validated directly on the server.

### Show the offer at the right time

Display an order bump when:

* One or more selected products are in the cart.
* A product belongs to a selected category.
* The cart subtotal or item quantity is greater or less than a defined value.
* The customer is logged in or logged out.
* Users have specific roles, such as customers, subscribers, or other registered roles.
* The customer's country, state, or region matches the configured locations.
* The current date falls on a selected weekday or matches a specific date.
* The current time falls within the configured time range.
* Any of the selected WooCommerce coupons is applied.

Product and category triggers can work together, and category-only campaigns are also supported. Add a schedule for specific dates, recurring weekdays, or a time range in the WordPress site timezone. BumpMint excludes its own products from subtotal and quantity rules so an offer cannot trigger itself.

Customer targeting can include everyone, logged-in users, logged-out visitors, or specific WordPress roles. Location targeting uses WooCommerce's current shipping country and region, with billing as a fallback. Select entire countries or narrow individual countries to states or regions, and choose whether an offer remains hidden or visible while the location is unknown.

Coupon targeting can require any valid coupon currently applied to the cart or any one of the selected published WooCommerce coupons. Coupon requirements combine with the cart, customer, location, and schedule settings. Coupon discount compatibility continues to follow WooCommerce rules.

### Create compelling offers without losing control

Offer one or more simple products, specific purchasable variations, or supported bookable products as independently selectable add-ons. You can:

* Apply a fixed or percentage discount.
* Limit how many units receive the discounted price.
* Hide an offered product when it is already in the cart.
* Show the original and offer prices together.
* Use a custom promotional banner, image, title, and description.
* Insert dynamic `{product}` and `{price}` placeholders.

Prices are calculated from the effective WooCommerce price and enforced on the server. If a rule stops matching, BumpMint removes only the cart line created by that offer and preserves independently added products.

### Complete Feature Overview

* **Multiple order bumps:** Manage multiple checkout campaigns from WordPress.
* **Campaign status:** Activate or deactivate an order bump without deleting its settings.
* **Customer targeting:** Show offers to everyone, logged-in users, logged-out visitors, or selected roles.
* **Location targeting:** Include or exclude countries and optional states or regions.
* **Coupon targeting:** Require any applied coupon or one of the selected WooCommerce coupons.
* **Multiple offers:** Add independently selectable products to one campaign.
* **Product and variation targeting:** Match any or all selected items.
* **Category targeting:** Match categories, child categories, or category-only rules.
* **Cart rules:** Target subtotal, item quantity, or every eligible checkout.
* **Availability scheduling:** Choose dates, weekdays, and an optional time range.
* **Flexible pricing:** Keep regular pricing or apply fixed and percentage discounts.
* **Quantity limits:** Cap discounted units for each offered product.
* **In-cart controls:** Hide an offered product already present in the cart.
* **Classic checkout positions:** Choose four offer placements.
* **Checkout Blocks:** Display offers below the block order summary.
* **WooCommerce Bookings:** Use the native form for supported bookable products.
* **Custom presentation:** Edit the banner, image, title, description, and placeholders.
* **Server-side safeguards:** Validate eligibility, products, stock, quantities, and prices.
* **Cart synchronization:** Remove only BumpMint lines that lose eligibility.
* **Local Analytics:** Track views, conversions, conversion rate, revenue, and top-performing bumps.
* **Report filters:** Compare results by date, weekday, hour, order bump, and currency.
* **WooCommerce reconciliation:** Track payments, cancellations, and refunds.
* **Private report data:** Store reports locally and reset them without deleting store records.
* **WooCommerce compatibility:** Support HPOS and compatible classic-checkout widgets.
* **Lightweight responsive assets:** Load focused CSS and JavaScript only where needed.

### Built-in Analytics without external tracking

The **Order Bumps > Analytics** dashboard shows:

* Views and currently valid paid conversions.
* Conversion rate and net order bump revenue.
* Top-performing order bump.
* Results by date, weekday, hour, campaign, and currency.

Conversions follow the current WooCommerce paid status, normally **Processing** or **Completed**. Cancellations, failed payments, and order bump refunds update the report automatically. Revenue excludes taxes and shipping, and reporting starts only after Analytics is installed.

Analytics data stays in the WordPress database. BumpMint does not store customer names, email addresses, IP addresses, or browser data in its report tables and does not send that data to third parties. Administrators can reset reports without deleting products, rules, or WooCommerce orders.

### Classic checkout, Checkout Blocks, and WooCommerce Bookings

BumpMint works with WooCommerce classic checkout and Checkout Blocks, and provides native configuration for supported WooCommerce Bookings products.

Classic checkout supports four positions: before or after order items, before payment methods, or before the **Place order** button. Checkout Blocks display eligible offers below the order summary through WooCommerce's Store API integration.

Supported WooCommerce Bookings products use their native product form for dates, availability, resources, and people. After configuration, the customer returns to checkout and receives the order bump discount on the booking price calculated by WooCommerce. Bookings that require confirmation use a separate purchase flow and are not offered.

### Compatibility

BumpMint supports:

* WooCommerce classic checkout and Checkout Blocks.
* Compatible checkout widgets that preserve WooCommerce hooks, including the native Elementor Pro Checkout widget.
* WooCommerce High-Performance Order Storage (HPOS).
* Simple products, specific purchasable variations, and WooCommerce Bookings products that do not require confirmation.
* Payment gateways compatible with the selected WooCommerce checkout type.

BumpMint displays offers during checkout and intentionally has no Cart Block offer interface.

== Installation ==

### Installation from the WordPress dashboard

1. Go to **Plugins > Add New Plugin**.
2. Search for **BumpMint** and click **Install Now**.
3. After installation, click **Activate**.

### Installation with the ZIP file

1. Download the official BumpMint package.
2. Go to **Plugins > Add New Plugin > Upload Plugin**.
3. Select the ZIP file without extracting it.
4. Click **Install Now**, then click **Activate**.

### Installation via FTP or file manager

1. Extract the ZIP file.
2. Upload the `bumpmint-order-bump-for-woocommerce` folder to `/wp-content/plugins/`.
3. Go to **Plugins > Installed Plugins** and activate BumpMint.

After activation, make sure WooCommerce is active and open **Order Bumps** to create your first campaign.

== Frequently Asked Questions ==

= How many campaigns can I create? =

You can create as many campaigns as you like. Unlike other plugins, BumpMint does not limit the number of campaigns or offered products.

= Can one campaign contain multiple products or variations? =

Yes. Add one or more simple products or specific purchasable variations. Each offer is displayed separately so customers can accept one or more products.

= Can I schedule an order bump? =

Yes. Run it every day, on specific calendar dates, or on recurring weekdays. You can also set a time range based on the WordPress site timezone, including ranges that continue past midnight.

= Which conditions can trigger an order bump? =

Use any or all selected products, product categories, cart subtotal, cart item quantity, or **Always show**. Category-only rules are also supported, and every condition can be combined with customer, location, coupon, and availability targeting.

= How does location targeting work? =

Choose countries from the locations where your WooCommerce store sells, optionally narrow individual countries to selected states or regions, and include or exclude those locations. BumpMint prioritizes the current shipping address and falls back to billing. You can hide or show the offer until WooCommerce knows enough of the address to evaluate the rule.

= Can an applied coupon control an order bump? =

Yes. An offer can require any valid coupon currently applied to the cart or any one of the selected published WooCommerce coupons. This controls offer eligibility only; whether coupon and order bump discounts combine follows the coupon settings and WooCommerce rules.

= Can I sell courses? =

Yes. Courses, digital products, and info products can be offered when they are set up in WooCommerce as simple products or specific purchasable variations. BumpMint handles the checkout offer; enrollment, file delivery, and content access depend on WooCommerce or the course plugin you use.

= Is the plugin lightweight? =

Yes. BumpMint loads its checkout assets only where needed and uses native WordPress and WooCommerce APIs. Analytics data stays in the store's database and does not load external tracking services or charting libraries.

= Does BumpMint support classic checkout and Checkout Blocks? =

Yes. Classic checkout provides four configurable positions. Checkout Blocks display offers below the order summary. BumpMint does not display offers on the cart page.

= Does BumpMint support WooCommerce Bookings? =

Yes, for bookable products that do not require confirmation. Customers use the native product form to choose required options and then return to checkout with the BumpMint discount applied to the calculated booking price.

= How are discounts and quantity limits protected? =

BumpMint validates the saved campaign, current cart, product, stock, price, and quantity on the server. Fixed and percentage discounts use the effective WooCommerce price, and the default discounted quantity limit is one unit, although the administrator can set a higher limit. This server-side validation adds an extra layer of security when customers place their orders.

= What does Analytics count? =

Analytics tracks order bump views, currently valid paid conversions, conversion rate, and net product revenue after applicable refunds. Taxes, shipping, and orders placed before tracking began are excluded. Report data stays in WordPress and can be reset by an administrator.

= Is BumpMint compatible with HPOS? =

Yes. BumpMint declares compatibility with WooCommerce High-Performance Order Storage (HPOS).

= Where can I get support or report a bug? =

Use the [WordPress.org support forum](https://wordpress.org/support/plugin/bumpmint-order-bump-for-woocommerce/) or report reproducible issues on [GitHub](https://github.com/andrewaires/bumpmint/issues).

== Screenshots ==

1. A targeted fixed-discount order bump selected in WooCommerce classic checkout.
2. Eligible order bump offers displayed below the order summary in WooCommerce Checkout Blocks.
3. Multiple independently selectable order bump products in classic checkout.
4. Local Analytics overview with views, paid conversions, conversion rate, net revenue, top-performing bump, and performance over time.
5. Analytics breakdown by weekday, hour, and individual order bump.
6. WordPress-native campaign management with rules, schedules, status, price, and checkout position.
7. Create a product- or category-triggered order bump and choose its availability.
8. Configure scheduling, offered products, classic checkout position, cart behavior, and discounts.
9. Customize banner text, title, description, dynamic placeholders, and product image.

== Changelog ==

= 1.7.0 - 2026-08-22 =
* Added active and inactive campaign controls without deleting saved settings or Analytics history.
* Added targeting for everyone, logged-in users, logged-out visitors, and selected WordPress roles.
* Added country and state or region targeting with include/exclude matching and configurable behavior for unknown locations.
* Added targeting based on any applied coupon or selected published WooCommerce coupons.
* Applied customer, location, and coupon requirements across classic checkout and Checkout Blocks with server-side validation.

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
* Added views, paid conversions, conversion rate, net order bump revenue, top-performing bump, and date, weekday, and hourly breakdowns.
* Synchronized reports with current WooCommerce paid statuses and BumpMint line refunds while excluding taxes and shipping.

See the [complete changelog](https://github.com/andrewaires/bumpmint/blob/main/changelog.txt) for earlier releases.
