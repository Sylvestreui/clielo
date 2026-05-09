=== Clielo ===
Contributors: sylvestreui
Tags: chat, orders, invoices, payments, client
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.6
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn any Custom Post Type into a complete client service platform — chat, orders, payments, invoices and notifications in one plugin.

== Description ==

Clielo turns any Custom Post Type into a full client service management system. Each post gets its own real-time chat, allowing clients and service providers to communicate directly, place orders, track progress and pay online.

**Core Features (Free)**

* Real-time chat attached to any CPT post
* Service packs and options management with pricing, delays and descriptions
* Order workflow: pending → paid → started → completed → revision → accepted
* Client account page with dashboard, orders, invoices and profile (`[clielo_my_account]` shortcode)
* Admin dashboard with statistics and recent activity
* In-app notification system
* Customisable accent colour and chat button position
* Fully responsive — mobile-friendly sidebar and chat
* WP-Cron warning if DISABLE_WP_CRON is active

**Premium Features (via Freemius)**

* Stripe Checkout integration for online payments (single, deposit, installments, monthly subscription)
* Automatic PDF invoice generation
* Email notifications (new order, status change, payment links, reminders)
* Full email template editor with live preview
* Todo list per order with progress tracking
* Automatic payment link sending on due date (WP Cron)
* Configurable payment reminders N days before due date
* Unlimited services (free plan limited to 1)
* Extra pages, express delivery and maintenance pricing options
* Elementor Dynamic Tags integration

== Installation ==

1. Upload the `clielo` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Clielo → Settings** and choose your Custom Post Type
4. Create a page and add the `[clielo_my_account]` shortcode for the client account dashboard (orders, invoices, profile). Optionally, add `[clielo_account]` in your header/navbar as a login/avatar widget.
5. *(Premium)* Configure Stripe under **Clielo → Stripe** — paste your API keys and webhook secret
6. *(Premium)* Configure email notifications under **Clielo → Notifications**

**WP Cron (for automatic payment links)**

On low-traffic sites, add a real system cron job for reliable daily scheduling:
`* 8 * * * curl https://yoursite.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1`

== Frequently Asked Questions ==

= Which Custom Post Types are supported? =

Any public Custom Post Type registered on your site. Configure it in Clielo → Settings.

= Is Stripe required? =

No. Without Stripe (free plan), orders follow a manual chat-based workflow. Enabling Stripe (premium) adds automatic payment collection via Stripe Checkout.

= Can I customise the chat appearance? =

Yes. You can change the accent colour and chat button position (bottom-left, bottom-right, top-left, top-right) from the settings page. All UI elements adapt to the chosen colour automatically.

= What payment modes does Stripe support? =

Four modes per service: single payment, 50% deposit + balance on delivery, installments (40% upfront + N monthly payments), or pure monthly subscription.

= Where is the webhook URL for Stripe? =

Go to **Clielo → Stripe** in your admin. The webhook URL is displayed there — copy it into your Stripe Dashboard under Developers → Webhooks. Select the `checkout.session.completed` event.

== External Services ==

This plugin optionally connects to the following third-party services:

= Stripe (premium only) =
Used to process online payments via Stripe Checkout. Only active when Stripe is enabled by the site administrator and API keys are configured.
* Service: https://stripe.com
* Privacy Policy: https://stripe.com/privacy
* Terms of Service: https://stripe.com/legal

= Freemius =
Used to manage plugin licensing, upgrades and trials. Activated on first use of the plugin admin area (opt-in required).
* Service: https://freemius.com
* Privacy Policy: https://freemius.com/privacy

No data is transmitted to external services in the free plan without explicit configuration by the site administrator.

== Changelog ==

= 1.0.6 =
* Elementor widget: add comprehensive style controls for all service card elements — section labels, pack state (selected/unselected bg + border), option prices, footer recap (background, separators, subtotal, total, delay)
* Elementor widget: JS pack switching now uses CSS variables — pack colors update live when Elementor controls change
* Elementor widget: all new controls update live in Elementor editor with zero PHP re-render

= 1.0.5 =
* Elementor widget: add style controls for chat button (color, size, radius) and chat popup (background, radius, width, height)
* Elementor widget: chat button and popup now use CSS variables — controls update live in Elementor editor via :root selectors

= 1.0.4 =
* Elementor widget: live preview now works correctly for all style controls (CSS variables via style tag, overridable by Elementor selectors)
* Elementor widget: add comprehensive style controls — card background, pack name/price typography, features, options, button (color, size, weight, radius)
* Elementor widget: fix border radius controls (card and button) now apply correctly

= 1.0.3 =
* Elementor widget: color override now updates live in editor via CSS variables
* Elementor widget: service options auto-detect current post, no manual ID needed

= 1.0.2 =
* Fix UTF-8 BOM in PHP files causing Freemius license activation to fail

= 1.0.1 =
* Add Elementor widget for service options (packs, options, order button toggle)
* Premium features now unlock instantly via license key activation (no separate zip required)
* Rename all internal identifiers from serviceflow to clielo

= 1.0.0 =
* Initial public release

== Upgrade Notice ==

= 1.0.0 =
Initial public release.
