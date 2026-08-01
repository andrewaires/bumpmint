# BumpMint – Order Bump for WooCommerce

![BumpMint banner](.wordpress-org/banner-1544x500.png)

BumpMint is a lightweight WooCommerce order bump plugin that lets store owners create targeted, one-click checkout offers with flexible display rules, secure server-side discounts, and multiple positions.

[WordPress.org](https://wordpress.org/plugins/bumpmint-order-bump-for-woocommerce/) · [Report an issue](https://github.com/andrewaires/bumpmint/issues)

## Turn checkout into a smarter revenue opportunity

Present relevant add-ons when customers are ready to complete their purchase. BumpMint makes it easy to create multiple order bumps, target each offer according to the cart, and customize the checkout experience without writing code.

### Highlights

- Launch multiple named order bumps.
- Offer fixed or percentage discounts.
- Trigger offers by product, cart subtotal, item quantity, or show them every time.
- Position offers before/after order items, before payment, or before the Place order button.
- Customize the banner, product image, title, description, and dynamic placeholders.
- Support simple products and specific purchasable variations.
- Validate rules, products, stock, and final prices on the server.
- Keep checkout lightweight, responsive, and synchronized with WooCommerce AJAX updates.
- Ready for community translations through the official WordPress.org translation platform.
- Run with WooCommerce HPOS compatibility.

## Screenshots

### Checkout experience

![BumpMint order bump on the WooCommerce checkout](.wordpress-org/screenshot-1.png)

### Focused one-click offer

![Responsive discounted order bump](.wordpress-org/screenshot-2.png)

### WordPress-native management

![Order bump rules list](.wordpress-org/screenshot-3.png)

### Flexible rules and positioning

![Order bump display rules and offer settings](.wordpress-org/screenshot-4.png)

### Custom content and secure discounts

![Order bump discount and content settings](.wordpress-org/screenshot-5.png)

## Display rules

BumpMint can show an offer when:

- A selected product or variation is in the cart.
- The classic checkout is displayed.
- The cart subtotal is greater or less than a configured value.
- The cart item quantity is greater or less than a configured value.

Items added by BumpMint are excluded from subtotal and quantity rules so an offer cannot activate or deactivate itself.

## Secure discounts

The browser sends only the saved rule ID and requested selection state. BumpMint resolves the canonical product and price on the server, verifies the current condition and stock, calculates the discount, and enforces the trusted price during WooCommerce cart total calculations.

## Requirements

- WordPress 6.5 or newer.
- WooCommerce 8.0 or newer.
- PHP 7.4 or newer.
- WooCommerce classic checkout.

WooCommerce Cart and Checkout Blocks are not supported in version 1.0.0.

## Installation

### From WordPress

1. Open **Plugins > Add New** in your WordPress dashboard.
2. Search for **BumpMint**.
3. Click **Install Now**, then **Activate**.
4. Open **Order Bumps** and create your first offer.

### Manual installation

1. Download the latest ZIP from [GitHub Releases](https://github.com/andrewaires/bumpmint/releases).
2. Open **Plugins > Add New > Upload Plugin**.
3. Upload the ZIP and activate BumpMint.

## License

BumpMint is licensed under the [GNU General Public License v2.0 or later](LICENSE).
