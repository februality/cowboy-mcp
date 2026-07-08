=== Cowboy MCP - manage your site with Claude, ChatGPT and other AI agents ===
Contributors: februality
Tags: mcp, mcp-server, claude, ai-agent, wp-cli
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude, ChatGPT & AI agents to your WordPress site — publish content, run your store, and fix problems, all from a chat.

== Description ==

**Stop clicking around wp-admin. Just tell your AI what you want.**

Cowboy MCP connects Claude Code, Codex, and other AI agents straight to your WordPress site. Ask in plain language — "publish these three drafts," "why is the checkout page 500-ing," "bump every Summer Sale price 20%," "clear the cache and re-check site health" — and your agent gets it done.

Most WordPress AI plugins let an assistant write blog posts. Cowboy MCP lets your agent actually *run the site*: content, yes, but also the terminal-level work you'd normally stop and do by hand — WP-CLI, files, the database, error logs, and diagnostics. When something breaks, your agent can find it and fix it instead of just apologizing.

= What you can do with it =

* **Manage content by chat** — draft, edit, schedule, and publish posts and pages; upload media; sort categories, tags, and menus.
* **Run your store** — update WooCommerce products, prices, orders, and inventory in bulk.
* **Fix things that break** — read error logs, test emails and HTTP requests, inspect hooks and REST routes, and repair database tables. No SSH required.
* **Do the developer stuff** — run WP-CLI commands, edit files in `wp-content`, and take site snapshots before big changes.
* **Get more done, faster** — your agent stays sharp and accurate even with a big toolset, so it picks the right action the first time.

= Why site owners choose it =

* **You stay in control — and you can undo.** Safe mode asks before anything destructive, every change can be previewed first, and every action your agent takes is logged. If something's not right, undo most individual changes with one click, or roll the whole database back to a checkpoint — all from the Activity tab.
* **Your data stays yours.** Self-hosted, no external services, no accounts, no phone-home. Your agent connects *to* your site — nothing leaves your server.
* **Set up in two minutes.** Generate a key (or one-click sign-in for the Claude desktop and web apps), paste one command, and you're connected.
* **No subscription.** Free and open source. Bring the AI agent you already use.

= Works with =

Claude Code, Codex, Opencode, the Claude desktop and web apps (one-click sign-in), plus Cursor, Windsurf, Cline, Zed, VS Code, ChatGPT, Gemini, and n8n — anything that speaks MCP.

== Installation ==

1. Install and activate Cowboy MCP from the Plugins screen.
2. Go to **Settings > Cowboy MCP** and click **Generate API Key** (it's shown once — copy it).
3. Connect your agent and start giving it instructions.

**Claude Code:**

    claude mcp add --transport http your-site /wp-json/cowboy-mcp/v1/endpoint --header "Authorization: Bearer YOUR_API_KEY"

**Claude desktop / web (no terminal):** enable the OAuth connector under **Settings > Cowboy MCP > Settings > Desktop Connector**, add your site as a custom connector in Claude, and approve with one click. (Requires a public HTTPS site.)

Codex and other clients: see the setup guides in the plugin settings.

== Frequently Asked Questions ==

= What is MCP? =

The Model Context Protocol is an open standard that lets AI agents use external tools. Cowboy MCP turns your WordPress site into one of those tools, so your agent can act on it directly.

= Which AI agents work with it? =

Any MCP-compatible client over Streamable HTTP — including Claude Code, Codex, Opencode, the Claude desktop and web apps, Cursor, Windsurf, Cline, Zed, VS Code, ChatGPT, and Gemini.

= Is it safe to use on a live site? =

Yes, with care. You're handing an AI real control, so Cowboy MCP is built to keep you in charge: keys are hashed and shown once, requests are rate-limited, destructive actions need confirmation, and changes can be previewed before they run. If a change turns out wrong, you can usually undo it — or restore the database to an earlier checkpoint — from the Activity tab, and every action is written to an audit log. Review it regularly.

= Does it send my data anywhere? =

No. Cowboy MCP makes no outbound connections and never phones home. Your agent talks to your site; your data stays on your server.

= Do I need to be a developer? =

No. Publishing content and running your store work through plain conversation. The developer tools (WP-CLI, files, database) are there when you want them, and gated behind safe mode until you say go.

== Screenshots ==

1. Connection tab — pick your AI tool (Claude Code, Claude Desktop, claude.ai, ChatGPT, Cursor, Codex, and more) and copy the ready-made setup command. Existing API keys are listed with one-click revoke.
2. Settings tab — turn the server on or off, require Safe Mode confirmation for destructive actions, enable the one-click Desktop/web (OAuth) connector, set a per-key rate limit, and opt in to advanced Power Mode.
3. Logs tab — a structured, filterable audit log of every MCP tool call, error, and auth event, auto-pruned after 30 days.
4. About tab — what Cowboy MCP does, with links to the project site and source.
5. One-click browser sign-in — the connector opens a consent screen on your own site; no access is granted until you approve it as an administrator.

== Changelog ==

= 1.4.1 =
* New: redesigned connection page — pick your app (claude.ai, Claude Desktop, ChatGPT, Claude Code, Codex, Opencode, Cursor, Gemini CLI) from a sidebar and follow steps tailored to it.
* New: connect from ChatGPT — custom connectors (Developer mode) work with the built-in one-click sign-in.
* Fixed: OAuth setup steps now appear immediately after enabling the Desktop Connector.
* Fixed: the connection-page preference is now cleaned up on uninstall.

= Earlier versions =

For the changelog of earlier releases, see [changelog.txt](https://plugins.svn.wordpress.org/cowboy-mcp/trunk/changelog.txt).
