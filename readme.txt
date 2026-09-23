=== WooCommerce Analytics MCP ===
Contributors: clariq
Tags: woocommerce, analytics, ai, mcp, claude
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
WC requires at least: 8.0
WC tested up to: 9.0
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Talk to your WooCommerce store. Connect Claude (or any MCP-compatible AI assistant) to your store analytics — self-hosted and private, or via Clariq Cloud.

== Description ==

**Ask your store questions in plain English.** This plugin connects your WooCommerce store to AI assistants via the Model Context Protocol (MCP): sales performance, product analytics, customer retention, coupon attribution, and more.

Two ways to run it:

= Local Bridge (self-hosted, free, unlimited) =
Analytics run on *your* server against your own database. Your order data never leaves your infrastructure — no account, no quota. The plugin exposes a small HMAC-authenticated REST bridge (`/wp-json/mcp-bridge/v1/*`) that the open-source [Clariq MCP Server](https://github.com/clariqapp/mcp-server) calls from your own machine.

= Clariq Cloud (hosted) =
Sync orders to Clariq Cloud for a managed dashboard, longer history, and team access. Optional — the plugin is fully functional without it.

**Privacy & security**

* Zero customer PII required for analytics
* Every bridge request is authenticated with HMAC-SHA256 over the raw request body
* Timing-safe signature comparison (`hash_equals`)
* Bridge secrets are generated on your site and can be rotated anytime
* Bridge is disabled unless Local Bridge mode is active

**Included analytics tools**

* Sales performance (revenue, order count, AOV by day/week/month)
* Product analytics (top products & variations by revenue or quantity)
* Customer insights (repeat rate, cohort retention, LTV)
* Marketing attribution (coupon usage, discount impact)
* Technical analytics (payment methods, order statuses, device types)

== Installation ==

1. Upload the plugin and activate it (WooCommerce must be active).
2. Go to **WooCommerce → Clariq Analytics**. Local Bridge mode is enabled by default.
3. Copy the bridge secret and the ready-made `claude_desktop_config.json` snippet shown in the **Local Bridge Setup** panel.
4. Install the MCP server on your computer: `uvx --from clariq-mcp-server clariq-mcp` (requires Python 3.11+).
5. Paste the snippet into your MCP client config and restart the client.
6. Ask Claude about your store!

== Frequently Asked Questions ==

= Do I need a Clariq account? =
No. Local Bridge mode works entirely on your own infrastructure. A Clariq account is only needed for the optional hosted cloud mode.

= Does it slow down my store? =
No. The bridge only runs read-only analytics queries when your AI assistant asks a question. Background sync (cloud mode) uses Action Scheduler with small batches.

= Where is my data stored? =
In Local Bridge mode, nowhere new — everything stays in your existing WordPress/WooCommerce database. In Cloud mode, order metrics (no customer PII) are synced to Clariq Cloud.

= Pretty permalinks are required? =
Yes — the bridge lives under `/wp-json/`, which needs WordPress permalinks enabled (any non-"Plain" setting).

== Screenshots ==

1. Settings page with the Local Bridge Setup panel (secret, MCP client config, connection test)
2. Connection mode toggle: Local Bridge vs Clariq Cloud

== Changelog ==

= 1.3.1 =
* Automated release packaging and GitHub Release distribution with validated Clariq-branded ZIP artifacts.
* Updated the plugin display and downloadable package identity to Clariq Analytics MCP.

= 1.3.0 =
* Local Bridge is now the default mode for fresh installs — self-hosted setup works with no account
* New "Local Bridge Setup" panel: bridge endpoint, secret reveal/copy/rotate, MCP client config snippet, one-click connection test
* Added `POST /wc-mcp/v1/bridge/rotate-secret` endpoint

== Upgrade Notice ==

= 1.3.1 =
Improves release packaging and presents the plugin as Clariq Analytics MCP; existing installations remain compatible.

= 1.3.0 =
Fresh installs now default to Local Bridge (self-hosted) mode. Existing connected stores are unaffected.
