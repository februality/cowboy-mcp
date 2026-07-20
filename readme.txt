=== Cowboy MCP - MCP Server with Undo for Claude, ChatGPT, Gemini & Cursor ===
Contributors: februality
Tags: mcp, mcp-server, claude, chatgpt, ai-agent
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.5.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

MCP server for Claude, ChatGPT & Gemini. Vibe code your site, run WooCommerce, fix bugs by chat. Undo any change. Free.

== Description ==

**Stop clicking around wp-admin. Just tell your AI what you want.**

* 💬 **Run your whole site by chat** - content, media, WooCommerce, files, the database, WP-CLI, and diagnostics, not just blog posts.
* ↩️ **Undo any change** - a per-change undo journal and one-click database checkpoints let you roll back a single edit or the entire site.
* 🆓 **Free and open source** - every tool included, with deep WooCommerce, ACF, Elementor, and Wordfence support. No Pro tier, no subscription.
* 🔒 **Your data stays yours** - self-hosted, no accounts, no phone-home. Your agent connects to your site; nothing leaves your server.
* 🔌 **Works with your AI agent** - Claude, ChatGPT, Cursor, Codex, Gemini, and any MCP client. Set up in about two minutes.

Cowboy MCP is a full MCP server inside WordPress - Claude Code, Codex, and other AI agents connect straight to your site. Ask in plain language - "publish these three drafts," "why is the checkout page 500-ing," "bump every Summer Sale price 20%," "clear the cache and re-check site health" - and your agent gets it done.

Most WordPress AI plugins let an assistant write blog posts. Cowboy MCP lets your agent actually *run the site*: content, yes, but also the terminal-level work you'd normally stop and do by hand - WP-CLI, files, the database, error logs, and diagnostics. When something breaks, your agent can find it and fix it instead of just apologizing.

= What you can do with it =

* **Manage content by chat** - draft, edit, schedule, and publish posts and pages; upload media; sort categories and tags.
* **Run your store** - update WooCommerce products, prices, orders, and inventory in bulk.
* **Fix things that break** - read error logs, test emails and HTTP requests, inspect hooks and REST routes, and repair database tables. No SSH required.
* **Do the developer stuff** - run WP-CLI commands, edit files in `wp-content`, and take site snapshots before big changes.
* **Vibe code your site** - describe the theme tweak or feature you want; your agent writes the code, checks the error log, and fixes what it broke. With per-change undo and database checkpoints, vibe coding a live site stops being reckless.
* **Get more done, faster** - your agent stays sharp and accurate even with a big toolset, so it picks the right action the first time.

= Works with =

Claude Code, Codex, Opencode, the Claude desktop and web apps (one-click sign-in), plus Cursor, Windsurf, Cline, Zed, VS Code, ChatGPT, Gemini, and n8n - anything that speaks MCP.

== Installation ==

1. Install and activate Cowboy MCP from the Plugins screen.
2. Go to **Settings > Cowboy MCP** and click **Generate API Key** (it's shown once - copy it).
3. Connect your agent and start giving it instructions.

**Claude Code:**

    claude mcp add --transport http your-site /wp-json/cowboy-mcp/v1/endpoint --header "Authorization: Bearer YOUR_API_KEY"

**Claude desktop / web (no terminal):** enable the OAuth connector under **Settings > Cowboy MCP > Settings > Desktop Connector**, add your site as a custom connector in Claude, and approve with one click. (Requires a public HTTPS site.)

Codex and other clients: see the setup guides in the plugin settings.

== Frequently Asked Questions ==

= What is MCP? =

The Model Context Protocol is an open standard that lets AI agents use external tools. Cowboy MCP turns your WordPress site into one of those tools, so your agent can act on it directly.

= Which AI agents work with it? =

Any MCP-compatible client over Streamable HTTP - including Claude Code, Codex, Opencode, the Claude desktop and web apps, Cursor, Windsurf, Cline, Zed, VS Code, ChatGPT, and Gemini.

= How do I connect Claude to my WordPress site? =

Install Cowboy MCP, generate an API key under **Settings > Cowboy MCP**, and add your site to Claude Code with one command - shown ready to copy on the Connection tab. For the Claude desktop and web apps, enable the Desktop Connector and approve the one-click sign-in; no terminal needed. ChatGPT, Cursor, Codex, and other clients have step-by-step guides on the same tab.

= Does it work with WooCommerce? =

Yes. When WooCommerce is active, 40 store tools light up: products and variations, orders and refunds, coupons, customers, stock, shipping zones, tax rates, payment gateways, and sales reports - so your agent can run the store, not just describe it.

= Is it safe to use on a live site? =

Yes, with care. You're handing an AI real control, so Cowboy MCP is built to keep you in charge: keys are hashed and shown once, requests are rate-limited, destructive actions need confirmation, and changes can be previewed before they run. If a change turns out wrong, you can usually undo it - or restore the database to an earlier checkpoint - from the Activity tab, and every action is written to an audit log. Review it regularly.

= Can I vibe code my WordPress site? =

Yes - that's what it's built for. Your agent can write and edit theme and plugin code in `wp-content`, run WP-CLI, and read the error log to fix what it broke. Take a database checkpoint first (or let auto-checkpoint do it), and every change lands in the undo journal - so vibe coding a live site doesn't have to be a leap of faith.

= Does it send my data anywhere? =

No. Cowboy MCP makes no outbound connections and never phones home. Your agent talks to your site; your data stays on your server.

= Do I need to be a developer? =

No. Publishing content and running your store work through plain conversation. The developer tools (WP-CLI, files, database) are there when you want them, and gated behind safe mode until you say go.

== Screenshots ==

1. Connection tab - pick your AI tool (Claude Code, Claude Desktop, claude.ai, ChatGPT, Cursor, Codex, and more) and copy the ready-made setup command. Existing API keys are listed with one-click revoke.
2. Settings tab - turn the server on or off, require Safe Mode confirmation for destructive actions, enable the one-click Desktop/web (OAuth) connector, set a per-key rate limit, tune the undo journal and database checkpoints, and opt in to advanced Power Mode.
3. Activity tab - a per-change undo journal: review every change your agents made and roll any of them back individually, plus one-click database checkpoints you can restore the whole site to.
4. Logs tab - a structured, filterable audit log of every MCP tool call, error, and auth event, auto-pruned after 30 days.
5. About tab - what Cowboy MCP does, with links to the project site and source.
6. One-click browser sign-in - the connector opens a consent screen on your own site; no access is granted until you approve it as an administrator.

== Changelog ==

= 1.5.3 =
* New: Connection Doctor - a one-click self-test on the Connection tab that checks HTTPS, public reachability, REST availability, and OAuth discovery, names the likely blocker (Cloudflare, ModSecurity, Basic Auth, cache plugins), and gives you a copy-pasteable report. Also available as the wp_connection_doctor MCP tool and the `wp cowboy-mcp doctor` WP-CLI command.

= Earlier versions =

For the changelog of earlier releases, see [changelog.txt](https://plugins.svn.wordpress.org/cowboy-mcp/trunk/changelog.txt).
