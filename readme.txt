=== Cowboy MCP - Free MCP Server with Undo for Claude & ChatGPT ===
Contributors: februality
Tags: mcp, mcp-server, model-context-protocol, claude, claude-code
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.6.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free WordPress MCP server (Model Context Protocol) for Claude, ChatGPT, Cursor & Claude Code. Run your whole site by chat, undo any change.

== Description ==

Cowboy MCP is a free, open-source **WordPress MCP server**: a Model Context Protocol endpoint that runs inside your own site, so Claude, ChatGPT, Cursor, Claude Code, Codex, Gemini and any other MCP client can manage WordPress in plain English. Every tool is included, every change your agent makes can be undone, and nothing is routed through a third-party relay - the agent talks straight to your site.

**Stop clicking around wp-admin. Just tell your AI what you want.**

* 💬 **Do everything by chat** - create and edit posts and pages, run your WooCommerce shop, manage users and menus, tidy the media library, change settings, and fix errors when they crop up.
* ↩️ **Undo any change** - a per-change undo journal and one-click database checkpoints let you roll back a single edit or the entire site.
* 🔄 **Updates plugins & themes safely** - backup first, health check after, one-command undo if anything breaks.
* 🛡️ **Safe by default** - safe mode confirms before anything destructive, you can preview any change before it runs, every key can be scoped to read-only, and every action is written to an audit log.
* 🆓 **Free and open source** - every tool included, with deep WooCommerce, Gutenberg, ACF, Elementor, Wordfence and SEO support. No Pro tier, no credits, no usage meter.
* 🔒 **Your data stays yours** - self-hosted, no accounts, no relay, no phone-home. Your agent connects to your site; nothing leaves your server.
* 🔌 **Works with your AI agent** - Claude, ChatGPT, Cursor, Codex, Gemini, Claude Code and any MCP client. Set up in about two minutes, on a live site or a local one.

> "More access than any other MCP offers, easy to use, LOVE the change journal and the checkpoints - safe if you break something, without having to run back ups on the host or yet another plugin." - a WordPress.org reviewer

Ask in plain language - "publish these three drafts," "why is the checkout page 500-ing," "bump every Summer Sale price 20%," "clear the cache and re-check site health" - and your agent gets it done. Most WordPress AI plugins let an assistant write blog posts. Cowboy MCP lets your agent actually *run the site*: content, yes, but also the terminal-level work you would normally stop and do by hand - WP-CLI, files, the database, error logs, and diagnostics. When something breaks, your agent can find it and fix it instead of just apologizing.

Try it without installing anything: click **Live Preview** above to open a throwaway WordPress Playground site with Cowboy MCP already active.

= What you can do with it =

* **Manage content by chat** - draft, edit, schedule, and publish posts and pages; upload media; sort categories and tags; build navigation menus.
* **Edit pages block by block** - your agent reads any page as a Gutenberg block tree and makes surgical edits: update a heading, move a section, swap a pattern. On block themes it can edit Site Editor templates and global styles too - all undoable.
* **Run the back office** - add and edit users, change roles, and tidy the media library: find every image missing alt text and fix it in one go. Deleted media is recoverable, and the site can never be left without an administrator.
* **Run your store** - update WooCommerce products, prices, orders, refunds, coupons and inventory in bulk; pull sales reports.
* **Fix things that break** - read error logs, test emails and HTTP requests, inspect hooks and REST routes, check transients and rewrite rules, and repair database tables. No SSH required.
* **Do the developer stuff** - run WP-CLI commands, edit files in `wp-content`, and take site snapshots before big changes.
* **Vibe code your site** - describe the theme tweak or feature you want; your agent writes the code, checks the error log, and fixes what it broke. With per-change undo and database checkpoints, vibe coding a live site stops being reckless.
* **Keep SEO tidy** - read, write and audit Yoast SEO or Rank Math meta across the site with one vocabulary.
* **Get more done, faster** - the server fronts its tools with a two-tool gateway (`cowboy_discover` + `cowboy_run`), so your agent stays sharp and accurate with a big toolset and picks the right action the first time.

= Tool coverage =

Up to 168 tools. The core set is always on; integrations light up automatically when their plugin is active.

* **Content** - posts, pages and custom post types (5) · taxonomies (4) · comments (4) · media (4) · menus (6) · options (1)
* **Gutenberg & Site Editor** - block tree read/edit with path addressing, block types, patterns, templates and template parts, global styles, navigations (15; 8 on classic themes)
* **Site administration** - users and roles (5) · plugins (6) · themes (5) · files in wp-content (4) · database health and repair (5) · WP-CLI, site info and search-replace (3) · site health (1)
* **Diagnostics** - error log, HTTP and email tests, hooks, transients, REST routes, thumbnails, rewrite rules, snapshot, Connection Doctor (10)
* **Safety** - list changes, undo a change, create/list/restore/delete database checkpoints (6) · batch execution and audit-log retrieval (2)
* **WooCommerce** - products and variations, orders and refunds, customers, coupons, tax and shipping settings, reports (40)
* **Wordfence** - scans, blocks, firewall, live traffic, activity, settings (17)
* **ACF** - field groups, fields, values, repeaters (9) · **Elementor** - templates, page content, global styles, widgets (7)
* **SEO** - Yoast SEO and Rank Math meta read/write/audit (4) · **Cache** - WP Rocket, LiteSpeed Cache, W3 Total Cache (4) · **Forms** - WPForms, Gravity Forms, Contact Form 7 (1)

Plus 17 read-only MCP resources (site info, recent posts, plugin list, the full tools catalog, and more), 4 resource templates, and 8 guided workflow prompts (site audit, troubleshooting, SEO optimization, security hardening, performance, content migration, bulk updates, WooCommerce setup).

= Connect Claude Code to WordPress =

Generate an API key under **Settings > Cowboy MCP**, then run one command in your terminal:

    claude mcp add --transport http your-site https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint --header "Authorization: Bearer YOUR_API_KEY"

The Connection tab shows this command pre-filled with your site's endpoint. Ask Claude Code to "list my draft posts" and it will call the matching tool.

= Connect Claude (desktop and web) with one click =

No terminal and no key to paste: turn on the **Desktop Connector** under **Settings > Cowboy MCP > Settings**, add your site's endpoint as a custom connector in the Claude desktop or web app, and approve the sign-in on your own site as an administrator. The consent screen lets you choose full access, read-only, or a hand-picked list of tools. This is a standard OAuth 2.1 flow and requires a public HTTPS site; on a local site, Claude Desktop connects through the small `mcp-remote` bridge shown on the Connection tab instead.

= Connect ChatGPT to WordPress =

ChatGPT connects to your site as a custom connector (Developer Mode) using the same one-click sign-in. It needs a public HTTPS site because ChatGPT connects from OpenAI's servers. The Connection tab walks through the steps.

= Connect Cursor, Windsurf, Cline, Zed and VS Code =

Add one block to your editor's MCP config (for Cursor, `~/.cursor/mcp.json`):

    {
      "mcpServers": {
        "your-site": {
          "url": "https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint",
          "headers": { "Authorization": "Bearer YOUR_API_KEY" }
        }
      }
    }

Any client that speaks Streamable HTTP with a Bearer header works the same way.

= Connect Codex CLI =

    export COWBOY_MCP_API_KEY="YOUR_API_KEY"
    codex mcp add your-site --url https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint --bearer-token-env-var COWBOY_MCP_API_KEY

= Connect Gemini CLI =

    gemini mcp add --transport http your-site https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint --header "Authorization: Bearer YOUR_API_KEY"

= n8n, Opencode and other MCP clients =

Cowboy MCP is a standard Streamable HTTP MCP server (JSON-RPC 2.0). Point any MCP client - n8n, Opencode, LibreChat, your own agent - at `/wp-json/cowboy-mcp/v1/endpoint` with an `Authorization: Bearer` header.

= Local development sites =

Local, Studio, MAMP, DevKinsta, wp-env, Docker - it works the same. Terminal tools (Claude Code, Cursor, Codex, Gemini CLI) run on the same computer as your local site and connect with an API key exactly like on a live site, no public URL or tunnel needed. Claude Desktop connects through the `mcp-remote` bridge; the Connection tab detects local sites and shows the ready-to-copy config. Only the cloud apps - claude.ai and ChatGPT - need a public HTTPS address.

= Built for live sites =

You are handing an AI real control, so Cowboy MCP is built to keep you in charge:

* **Safe mode** (on by default) - destructive tools refuse to run until the agent resends the call with explicit confirmation, and the refusal includes a preview of what would have happened.
* **Dry run** - every non-read-only tool accepts a dry-run flag that reports exactly what would change without touching anything.
* **Per-change undo journal** - before-state snapshots for every journaled change; roll back a single edit from the Activity tab or by asking your agent; seven-day retention by default; conflicts are detected if something else changed the same row since.
* **Database checkpoints** - one-click snapshots of your site's database tables, restorable in one click; taken automatically before plugin and theme updates and before mutating WP-CLI commands. Checkpoints restore tables, not uploaded files or code.
* **Safe plugin and theme updates** - file backup first, database checkpoint, then a health check that automatically restores the previous version if the site breaks.
* **Audit log** - every tool call, error and authentication event, with key, tool, arguments and result, on the Logs tab; pruned after 30 days.
* **Scoped credentials** - each API key and each OAuth connection can be full access, read-only, or a custom list of tools; a read-only key cannot write even if the agent tries.
* **Hashed keys, rate limits, origin checks** - keys are shown once and stored as one-way hashes, requests are rate-limited per key (120/minute by default), and requests from unknown browser origins are rejected.
* **Guardrails you cannot talk your way past** - a denylist of sensitive options, dangerous SQL and WP-CLI commands, SSRF protection on outbound requests, path confinement to `wp-content`, self-delete and last-administrator protection, and a Power mode for the rare job that needs the gloves off - which only a human can switch on in wp-admin. The agent can never grant itself more power through the API.
* **Connection Doctor** - a one-click self-test that checks HTTPS, reachability, REST, OAuth discovery and common host blockers (Cloudflare challenges and bot rules, ModSecurity-style firewalls, LiteSpeed caching), names the exact thing in the way, and hands you a report you can paste into a support topic.

= How Cowboy MCP is different =

* **Nothing in the middle.** Some WordPress MCP products route every request through their own cloud relay and meter your agent's actions in credits. Cowboy MCP's endpoint runs on your server; there is no account, no relay, no quota. Your content never passes through us.
* **Every tool is free.** Some plugins gate the useful tools - plugins, themes, the database, WooCommerce - behind a Pro licence. Cowboy MCP ships all of them, GPL-licensed, with no paid tier.
* **Typed tools with undo, not a PHP shell.** Power tools that let an agent execute arbitrary PHP are built for development and staging copies, with backups. Cowboy MCP gives the agent typed, annotated tools wrapped in safe mode, dry run, undo and checkpoints, so it can be trusted on the site that pays the bills. (Want the comparison? See [Cowboy MCP vs Novamira](https://cowboymcp.com/compare/cowboy-mcp-vs-novamira).)
* **Undo, checkpoints and an audit trail together.** Several MCP plugins now offer approval gates or a time-boxed undo. Cowboy MCP pairs per-change undo with whole-database checkpoints and an always-on audit log, plus dry run and safe mode in front of them - so you can see what happened, preview what will happen, and reverse either one change or all of them.
* **A complete server today, friendly to the official pieces.** WordPress core is growing an Abilities API and an official MCP adapter; they are a framework for exposing capabilities, not a turnkey server. Cowboy MCP is the turnkey server - install, generate a key, connect - and works on WordPress 6.2 or later with no Composer, Node.js or build step.

Setup guides for every client, the full capability list, the security model and head-to-head comparisons with Novamira, AI Engine, WPVibe, InstaWP, the WordPress MCP Adapter and StifLi Flex MCP live at [cowboymcp.com](https://cowboymcp.com).

= Works with =

Claude Code, Claude desktop and web apps (one-click sign-in), ChatGPT (Developer Mode connector), Cursor, Windsurf, Cline, Zed, VS Code, Codex CLI, Gemini CLI, Opencode, n8n and anything else that speaks MCP over Streamable HTTP.

Integrations that light up automatically: WooCommerce, Gutenberg and the Site Editor, Yoast SEO, Rank Math, Advanced Custom Fields (ACF), Elementor, Wordfence, WP Rocket, LiteSpeed Cache, W3 Total Cache, WPForms, Gravity Forms and Contact Form 7.

= External services =

Cowboy MCP sends no telemetry and makes no calls home. The only outbound connections are to WordPress.org, when your agent installs or updates plugins or themes, and any HTTP requests you explicitly ask your agent to make with the HTTP-request tool. Your AI client connects directly to your site; no third-party server sits in between.

= Support and reviews =

Questions and connection problems: post in the [support forum](https://wordpress.org/support/plugin/cowboy-mcp/) and paste the Connection Doctor report - topics are usually answered within a day. Bugs and feature requests: [GitHub issues](https://github.com/februality/cowboy-mcp/issues). If Cowboy MCP saves you time, a [review](https://wordpress.org/support/plugin/cowboy-mcp/reviews/#new-post) helps other site owners find it.

== Installation ==

1. Install and activate Cowboy MCP from the Plugins screen.
2. Go to **Settings > Cowboy MCP** and click **Generate API Key** (it is shown once - copy it).
3. Connect your agent and start giving it instructions.

**Claude Code:**

    claude mcp add --transport http your-site https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint --header "Authorization: Bearer YOUR_API_KEY"

**Claude desktop / web (no terminal):** enable the OAuth connector under **Settings > Cowboy MCP > Settings > Desktop Connector**, add your site as a custom connector in Claude, and approve with one click. (Requires a public HTTPS site; on a local site use the `mcp-remote` bridge shown on the Connection tab.)

**Cursor, Codex, Gemini CLI, ChatGPT and other clients:** the Connection tab shows a ready-to-copy setup for each, and the connection guides at [cowboymcp.com](https://cowboymcp.com) walk through every client step by step.

== Frequently Asked Questions ==

= What is MCP? =

The Model Context Protocol is an open standard that lets AI agents use external tools. Cowboy MCP turns your WordPress site into one of those tools, so your agent can act on it directly.

= Which AI agents work with it? =

Any MCP-compatible client over Streamable HTTP - including Claude Code, the Claude desktop and web apps, ChatGPT, Cursor, Windsurf, Cline, Zed, VS Code, Codex CLI, Gemini CLI, Opencode and n8n.

= How do I connect Claude to my WordPress site? =

Install Cowboy MCP, generate an API key under **Settings > Cowboy MCP**, and add your site to Claude Code with one command - shown ready to copy on the Connection tab. For the Claude desktop and web apps, enable the Desktop Connector and approve the one-click sign-in; no terminal needed. ChatGPT, Cursor, Codex and other clients have step-by-step guides on the same tab.

= Is there a paid version? =

No. Every tool, every integration and every safety feature is in this free plugin, licensed GPL-2.0. There is no Pro tier, no credit system and no usage cap beyond the per-key rate limit you set yourself.

= Does any traffic go through a third-party server? =

No. The MCP endpoint runs inside your WordPress install and your AI client connects to it directly. There is no hosted relay, no account with us and no telemetry.

= Does it work on a local development site (Local, Studio, MAMP, DevKinsta)? =

Yes. Terminal tools like Claude Code, Cursor, Codex and Gemini CLI run on the same computer as your local site, so they connect with an API key exactly like on a live site - no public URL needed. Claude Desktop connects through a small local bridge (`mcp-remote`); the Connection tab detects local sites and shows the ready-to-copy config. Only the cloud-side apps - claude.ai and ChatGPT - require a public HTTPS address, because they connect from the vendor's servers; a tunnel works for temporary testing, but be aware it exposes your whole dev site while it runs.

= Does it work with WooCommerce? =

Yes. When WooCommerce is active, 40 store tools light up: products and variations, orders and refunds, coupons, customers, stock, shipping zones, tax rates, payment gateways, and sales reports - so your agent can run the store, not just describe it.

= Is it safe to use on a live site? =

Yes, with care. You are handing an AI real control, so Cowboy MCP is built to keep you in charge: keys are hashed and shown once, requests are rate-limited, destructive actions need confirmation, and changes can be previewed before they run. Each API key and connection can also be scoped to read-only access or a hand-picked list of tools. If a change turns out wrong, you can usually undo it - or restore the database to an earlier checkpoint - from the Activity tab, and every action is written to an audit log. Review it regularly.

= What exactly can be undone, and what can't? =

Content, options, users, media deletions, menus, terms, comments, WooCommerce objects, SEO meta, Gutenberg and Site Editor edits, search-replace runs, and plugin or theme installs, updates and deletions are journaled with a before-state snapshot and can be undone individually (or as a batch) within the retention period - seven days by default. Database checkpoints roll back every site table to an earlier moment. Things with no inverse - a sent email, a cache flush, an arbitrary WP-CLI command, an outbound HTTP request - are recorded as not undoable rather than pretended otherwise; take a checkpoint first when you ask for those.

= Can I limit what an AI agent is allowed to do? =

Yes. Every API key and every OAuth connection carries a scope: full access, read-only, or a custom list of allowed tools, chosen when the key is created or the connection is approved. Safe mode adds confirmation for destructive tools on top, and the most powerful operations stay locked behind Power mode, which only an administrator can enable in wp-admin.

= The endpoint returns 401 or 404 - what now? =

Run the **Connection Doctor** on the Connection tab. It tests HTTPS, reachability, the REST API, OAuth discovery and the common host blockers (Cloudflare challenges and "Block AI bots" rules, web application firewalls such as ModSecurity, LiteSpeed caching of `/wp-json/`), names the exact thing in the way, and gives you a fix. If you are still stuck, paste the report into a new topic in the support forum.

= Claude or ChatGPT connects but says no tools are available =

Cowboy MCP lists two gateway tools (`cowboy_discover` and `cowboy_run`) instead of dumping 168 schemas into your agent's context; the agent discovers the tools it needs on demand. Ask it to "discover tools for WooCommerce" or read the `wordpress://tools/catalog` resource. If even the two gateway tools are missing, the key's scope may be empty - check it on the Connection tab.

= Is it like Novamira? =

Pretty much - it does what Novamira does, but all of it is free and built for live sites. Every tool is included, there is no Pro tier, and every change your agent makes can be undone. See the full [Cowboy MCP vs Novamira comparison](https://cowboymcp.com/compare/cowboy-mcp-vs-novamira).

= How is this different from the official MCP Adapter and the Abilities API? =

The Abilities API (WordPress 6.9+) and the official MCP Adapter are a framework: plugins register abilities, and the adapter exposes whatever was registered. Cowboy MCP is a complete, turnkey MCP server with its own toolset, safety layer, undo, audit log, scoping, OAuth connector and Connection Doctor, and it runs on WordPress 6.2 or later without Composer or Node.js. You can run both side by side.

= Can I vibe code my WordPress site? =

Yes - that is what it is built for. Your agent can write and edit theme and plugin code in `wp-content`, run WP-CLI, and read the error log to fix what it broke. Take a database checkpoint first (or let auto-checkpoint do it), and every change lands in the undo journal - so vibe coding a live site does not have to be a leap of faith.

= Does it send my data anywhere? =

No telemetry and no phone-home - your data stays on your server. The only outbound connections are to WordPress.org, when your agent installs or updates plugins and themes, plus any HTTP requests you explicitly ask your agent to make.

= Does it work on multisite? =

Cowboy MCP is built for single sites and is not network-aware. On a multisite network, activate it on each site you want an agent to manage (not network-wide) and generate keys on that site; its plugin tools do recognise network-activated plugins when activating or deactivating.

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

= 1.6.3 =
* New: local development, first-class - Cowboy MCP now works just as well on a local site (Local, Studio, MAMP, DevKinsta, and any localhost setup) as on a live one. No public URL or tunnel needed: Claude Code, Cursor, Codex, and Gemini CLI connect with an API key exactly as they do in production, and Claude Desktop connects through a small local bridge.
* New: the Connection tab recognises local sites and shows, per client, what works locally and what needs a public URL - with a ready-to-copy Claude Desktop config that bridges through mcp-remote (read-only key by default).
* New: Connection Doctor understands local sites - plain HTTP on a local site passes with local context, and the public-hostname check becomes a warning with fix steps for both local and public setups instead of a failure.
* New: self-signed certificate help - on loopback hosts the connection snippets offer a plain-http variant, with a clear warning against disabling TLS verification.
* Compatibility: tested with WordPress 7.1.

= Earlier versions =

For the changelog of earlier releases, see [changelog.txt](https://plugins.svn.wordpress.org/cowboy-mcp/trunk/changelog.txt).
