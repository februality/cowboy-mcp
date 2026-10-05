=== Cowboy MCP - Free MCP Server with Undo for Claude & ChatGPT ===
Contributors: februality
Donate link: https://cowboymcp.com/tip
Tags: mcp, ai-agent, model-context-protocol, claude, claude-code
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude, ChatGPT, Cursor & any AI agent to your site. Free, secure WordPress MCP server (Model Context Protocol). Undo any change.

== Description ==

Cowboy MCP connects your WordPress site to Claude, ChatGPT, Cursor, Gemini or any other AI agent, so your AI assistant can run the site for you - and you can undo anything it changed. It is a free, open-source, secure **WordPress MCP server** (Model Context Protocol) that runs inside your own site: no relay, no account, no Pro tier. The agent talks straight to your site.

**Stop clicking around wp-admin. Just tell your AI what you want.**

* 💬 **Do everything by chat** - create and edit posts and pages, run your WooCommerce shop, manage users and menus, tidy the media library, change settings, and fix errors when they crop up.
* ↩️ **Undo any change** - a per-change undo journal and one-click database checkpoints let you roll back a single edit or the entire site.
* 🔄 **Updates plugins & themes safely** - backup first, health check after, one-command undo if anything breaks.
* 🛡️ **Secure by default** - safe mode confirms before anything destructive, you can preview any change before it runs, every key can be scoped to read-only, and every action is written to an audit log.
* 🆓 **Free and open source** - every tool included, with deep WooCommerce, Gutenberg, ACF, Elementor, Beaver Builder, SiteOrigin, Wordfence and SEO support. No Pro tier, no credits, no usage meter.
* 🔒 **Your data stays yours** - self-hosted, no accounts, no relay, no phone-home. Your agent connects to your site; nothing leaves your server.
* 🔌 **Works with your AI assistant** - Claude, ChatGPT, Cursor, GitHub Copilot, Codex, Gemini, Claude Code and any MCP client. Set up in about two minutes, on a live site or a local one.

> "More access than any other MCP offers, easy to use, LOVE the change journal and the checkpoints - safe if you break something, without having to run back ups on the host or yet another plugin." - a WordPress.org reviewer

Ask in plain language - "publish these three drafts," "why is the checkout page 500-ing," "bump every Summer Sale price 20%," "clear the cache and re-check site health" - and your agent gets it done. Most WordPress AI plugins let an assistant write blog posts; Cowboy MCP lets your agent actually *run the site*, including WP-CLI, files, the database, error logs and diagnostics.

Try it without installing anything: click **Live Preview** above to open a throwaway WordPress Playground site with Cowboy MCP already active.

= What is a WordPress MCP server? =

MCP (Model Context Protocol) is the open standard AI assistants use to work with other software. A WordPress MCP server gives Claude, ChatGPT or any MCP-capable agent a set of typed, permission-checked actions on your site - publish a post, update a product, fix an error - instead of you copy-pasting between a chat window and wp-admin. Cowboy MCP is that server, installed like any other plugin.

= What you can do with it =

* **Manage content by chat** - draft, edit, schedule, and publish posts and pages; upload media; sort categories and tags; build navigation menus.
* **Edit pages block by block** - your agent reads any page as a Gutenberg block tree and makes surgical edits: update a heading, move a section, swap a pattern. On block themes it can edit Site Editor templates and global styles too - all undoable.
* **Run the back office** - add and edit users, change roles, and tidy the media library: find every image missing alt text and fix it in one go. Deleted media is recoverable, and the site can never be left without an administrator.
* **Run your store** - update WooCommerce products, prices, orders, refunds, coupons and inventory in bulk; pull sales reports.
* **Fix things that break** - read error logs, test emails and HTTP requests, inspect hooks and REST routes, check transients and rewrite rules, and repair database tables. No SSH required.
* **Do the developer stuff** - run WP-CLI commands, edit files in `wp-content`, and take site snapshots before big changes.
* **Vibe code your site** - describe the theme tweak or feature you want; your agent writes the code, checks the error log and fixes what it broke - with undo and checkpoints behind it.
* **Keep SEO tidy** - read, write and audit Yoast SEO, Rank Math, All in One SEO or SEOPress meta across the site with one vocabulary.
* **Roll back a post** - list a post's revisions, see a git-style diff of what changed, and restore any revision (the restore itself is undoable).
* **Run your events** - create and edit The Events Calendar events, venues and organizers; with Events Calendar Pro, manage recurring series and cancel single dates.
* **Plug into the WordPress Abilities API** - every tool is also a `cowboy-mcp/*` ability (with safe mode, the audit log and undo still in front), and abilities other plugins register become tools for your agent.
* **Works on any host** - shared hosting, managed WordPress, a VPS or a local dev site. Nothing to install beyond the plugin, and no WordPress.com account or Jetpack plan needed.

= Tool coverage =

Up to 203 tools. The core set is always on; integrations light up automatically when their plugin is active. Your agent sees two gateway tools (`cowboy_discover` and `cowboy_run`) and finds the rest on demand, so a big toolset never crowds its context.

* **Content** - posts, pages and custom post types (5) · taxonomies (4) · comments (4) · media (4) · menus (6) · options (1) · revisions: list, diff and restore (3)
* **Gutenberg MCP** - block tree read/edit with path addressing, block types, patterns, Site Editor templates and template parts, global styles, navigations (15; 8 on classic themes)
* **Site administration** - users and roles (5) · plugins (6) · themes (5) · files in wp-content (4) · database health and repair (5) · WP-CLI, site info and search-replace (3) · site health (1)
* **Diagnostics** - error log, HTTP and email tests, hooks, transients, REST routes, thumbnails, rewrite rules, snapshot, Connection Doctor (10)
* **Safety** - list changes, undo a change, create/list/restore/delete database checkpoints (6) · batch execution and audit-log retrieval (2)
* **WooCommerce MCP** - products and variations, orders and refunds, customers, coupons, tax and shipping settings, reports (40)
* **Wordfence** - scans, blocks, firewall, live traffic, activity, settings (17)
* **ACF MCP** - field groups, fields, values, repeaters (9)
* **Elementor MCP** - templates, page content, global styles, widgets (7)
* **Beaver Builder** - builder pages, layouts, modules, global settings (7)
* **SiteOrigin Page Builder** - layouts, row and widget edits, prebuilt layouts, widgets, settings, Widgets Bundle activation (12)
* **Events** - The Events Calendar events, venues, organizers (11) plus Events Calendar Pro recurrence and occurrences (2)
* **SEO MCP** - Yoast SEO, Rank Math, All in One SEO and SEOPress meta read/write/audit (4)
* **Cache** - WP Rocket, LiteSpeed Cache, W3 Total Cache (4)
* **Forms** - WPForms, Gravity Forms, Contact Form 7 (1)

Plus 17 read-only MCP resources, 4 resource templates and 8 guided workflow prompts (site audit, troubleshooting, SEO, security hardening, performance and more).

= Connect Claude Code to WordPress =

Generate an API key under **Settings > Cowboy MCP**, then run one command in your terminal:

    claude mcp add --transport http your-site https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint --header "Authorization: Bearer YOUR_API_KEY"

The Connections tab shows this command pre-filled with your site's endpoint.

= Connect Claude to WordPress (claude.ai and Claude Desktop) =

No terminal and no key to paste: turn on the **Desktop Connector** under **Settings > Cowboy MCP > Settings**, click **Add to Claude** on the Connections tab, and approve the sign-in on your own site as an administrator. The consent screen lets you choose full access, read-only, or a hand-picked list of tools. This is a standard OAuth 2.1 flow and needs a public HTTPS site.

= Connect ChatGPT to WordPress =

ChatGPT connects with the same one-click sign-in. You need ChatGPT on the web with a Plus, Pro, Business, Enterprise or Edu plan, and a public HTTPS site, because ChatGPT connects from OpenAI's servers.

1. On your site, turn on the **Desktop Connector** under **Settings > Cowboy MCP**, open the ChatGPT panel on the Connections tab and copy your connection link. If "New connections" shows as disabled there, click **Enable for 30 minutes**.
2. In ChatGPT, open **Settings > Security and login** and turn on **Developer mode**. On Business and Enterprise workspaces an admin has to allow Developer mode first.
3. Go to **Plugins**, click **+** and choose **Create MCP App**. Paste your connection link as the MCP server URL and choose **OAuth**.
4. ChatGPT opens a sign-in page on your site. Review it and click **Approve**.
5. In a chat, open the **+** menu, pick **Developer mode** and select your site.

= Connect Cursor, VS Code and GitHub Copilot, Windsurf, Cline and Zed =

Add one block to your editor's MCP config (for Cursor, `~/.cursor/mcp.json`), then restart the editor:

    {
      "mcpServers": {
        "your-site": {
          "url": "https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint",
          "headers": { "Authorization": "Bearer YOUR_API_KEY" }
        }
      }
    }

Windsurf (now Devin Desktop) uses the same block in `~/.config/devin/mcp_config.json`; Cline adds `"type": "streamableHttp"`; VS Code takes it in `.vscode/mcp.json` under `servers` with `"type": "http"`, for GitHub Copilot's agent mode; Zed takes `url` and `headers` under `context_servers`. Any Streamable HTTP client with a Bearer header works.

= Connect Codex CLI =

    export COWBOY_MCP_API_KEY="YOUR_API_KEY"
    codex mcp add your-site --url https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint --bearer-token-env-var COWBOY_MCP_API_KEY

Codex reads the key at every start, so also add the `export` line to your shell profile (e.g. `~/.zshrc`).

= Connect Gemini CLI =

    gemini mcp add --scope user --transport http your-site https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint --header "Authorization: Bearer YOUR_API_KEY"

= n8n, Opencode and other MCP clients =

Cowboy MCP is a standard Streamable HTTP MCP server (JSON-RPC 2.0). Point any MCP client - n8n, Opencode, LibreChat, your own agent - at `/wp-json/cowboy-mcp/v1/endpoint` with an `Authorization: Bearer` header. In Opencode, also set `"oauth": false` so it uses the key.

= Local development sites =

Local, Studio, MAMP, DevKinsta, wp-env, Docker - it works the same. Terminal tools connect with an API key, no public URL or tunnel needed; Claude Desktop uses the `mcp-remote` bridge shown on the Connections tab. Only claude.ai and ChatGPT need a public HTTPS address.

= Secure by design: built for live sites =

You are handing an AI real control, so Cowboy MCP is built to keep you in charge:

* **Safe mode** (on by default) - destructive tools refuse to run until the agent resends the call with explicit confirmation, with a preview of what would happen.
* **Dry run** - every non-read-only tool accepts a dry-run flag that reports exactly what would change without touching anything.
* **Per-change undo journal** - before-state snapshots for every journaled change; roll back one edit from the Activity tab or by asking your agent (seven days by default), with conflict detection.
* **Database checkpoints** - one-click database snapshots, restorable in one click, taken automatically before plugin and theme updates and mutating WP-CLI commands (tables only, not uploads or code).
* **Safe plugin and theme updates** - file backup first, database checkpoint, then a health check that automatically restores the previous version if the site breaks.
* **Audit log** - every tool call, error and authentication event, with key, tool, arguments and result, on the Logs tab; pruned after 30 days.
* **AI agent permissions** - each API key and each OAuth connection can be full access, read-only, or a custom list of tools; a read-only key cannot write even if the agent tries.
* **Hashed keys, rate limits, origin checks** - keys are shown once and stored as one-way hashes, requests are rate-limited per key (120/minute by default), and requests from unknown browser origins are rejected.
* **Guardrails you cannot talk your way past** - a denylist of sensitive options, dangerous SQL and WP-CLI commands, SSRF protection, file writes confined to `wp-content` with a PHP syntax check, and last-administrator protection. Power mode lifts some of these for rare jobs; only a human can switch it on in wp-admin, so the agent can never grant itself more power.
* **Connection Doctor** - a one-click self-test that names the exact thing blocking a connection (HTTPS, REST, OAuth discovery, Cloudflare, firewalls, caching) and gives you a report to paste into a support topic.

= How Cowboy MCP is different =

* **Nothing in the middle.** Some WordPress MCP products route requests through their own cloud relay, meter actions in credits, or need a hosted account or paid plan. Cowboy MCP runs on your server, on any host: no account, no relay, no quota.
* **Every tool is free.** Some plugins gate the useful tools - plugins, themes, the database, WooCommerce - behind a Pro licence. Cowboy MCP ships all of them, GPL-licensed, with no paid tier.
* **Typed tools with undo, not a PHP shell.** Tools that let an agent execute arbitrary PHP are built for development copies. Cowboy MCP gives the agent typed tools wrapped in safe mode, dry run, undo and checkpoints, so it can be trusted on the site that pays the bills. (Want the comparison? See [Cowboy MCP vs Novamira](https://cowboymcp.com/compare/cowboy-mcp-vs-novamira).)
* **A full safety net, not just an undo button.** Many MCP plugins now promise undo - often a time-limited token, or edits made on a duplicate first. Cowboy MCP adds a per-change journal with conflict detection, whole-database checkpoints, automatic restore of broken updates, dry run, safe mode and an always-on audit log - preview what will happen, see what did, reverse one change or all of them.
* **A complete server today, wired into the official pieces.** The Abilities API and the official MCP adapter are a framework; Cowboy MCP is the turnkey server - install, generate a key, connect - and on WordPress 6.9+ it bridges both ways. Needs WordPress 6.2+, no Composer, Node.js or build step.

Setup guides for every client, the security model and head-to-head comparisons live at [cowboymcp.com](https://cowboymcp.com).

= Works with =

Integrations that light up automatically: WooCommerce, Gutenberg and the Site Editor, Yoast SEO, Rank Math, All in One SEO, SEOPress, The Events Calendar (and Events Calendar Pro), Advanced Custom Fields (ACF), Elementor, Beaver Builder, SiteOrigin Page Builder and Widgets Bundle, Wordfence, WP Rocket, LiteSpeed Cache, W3 Total Cache, WPForms, Gravity Forms and Contact Form 7.

= External services =

Cowboy MCP sends no telemetry and makes no calls home. The only outbound connections are to WordPress.org, when your agent installs or updates plugins or themes, and any HTTP requests you explicitly ask your agent to make with the HTTP-request tool.

= Support and reviews =

Questions and connection problems: post in the [support forum](https://wordpress.org/support/plugin/cowboy-mcp/) and paste the Connection Doctor report - topics are usually answered within a day. Bugs and feature requests: [GitHub issues](https://github.com/februality/cowboy-mcp/issues). If Cowboy MCP saves you time, a [review](https://wordpress.org/support/plugin/cowboy-mcp/reviews/#new-post) helps other site owners find it.

== Installation ==

1. Install and activate Cowboy MCP from the Plugins screen.
2. Go to **Settings > Cowboy MCP** and click **Generate API Key** (it is shown once - copy it).
3. Connect your agent and start giving it instructions.

**Claude Code:**

    claude mcp add --transport http your-site https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint --header "Authorization: Bearer YOUR_API_KEY"

**Claude desktop / web (no terminal):** enable the OAuth connector under **Settings > Cowboy MCP > Settings > Desktop Connector**, click **Add to Claude** on the Connections tab (Claude opens with your site already filled in), and approve with one click. (Requires a public HTTPS site; on a local site use the `mcp-remote` bridge shown on the Connections tab.)

**Cursor, Codex, Gemini CLI, ChatGPT and other clients:** the Connections tab shows a ready-to-copy setup for each, and the connection guides at [cowboymcp.com](https://cowboymcp.com) walk through every client step by step.

== Frequently Asked Questions ==

= What is a WordPress MCP server? =

A plugin that lets AI assistants act on your site through the Model Context Protocol (MCP), the open standard Claude, ChatGPT, Cursor and other agents use to call external tools. Cowboy MCP turns your WordPress site into one of those tools, so your agent can publish, edit, update and fix things directly instead of telling you what to click.

= How do I connect ChatGPT to my WordPress site? =

Turn on the Desktop Connector in Cowboy MCP and copy your connection link from the ChatGPT panel on the Connections tab. In ChatGPT on the web, turn on **Developer mode** under **Settings > Security and login**, then go to **Plugins**, click **+ > Create MCP App**, paste the link and choose OAuth. Approve the sign-in on your site as an administrator, then pick **Developer mode** from the **+** menu in a chat. It needs a Plus, Pro, Business, Enterprise or Edu plan and a public HTTPS site.

= How do I connect Claude to my WordPress site? =

For the Claude desktop and web apps, enable the Desktop Connector, click **Add to Claude** on the Connections tab and approve the one-click sign-in - no terminal needed. For Claude Code, generate an API key under **Settings > Cowboy MCP** and run the one-line command shown ready to copy on the Connections tab.

= Is it free? =

Yes. Every tool, every integration and every safety feature is in this free plugin, licensed GPL-2.0. There is no Pro tier, no credit system and no usage cap beyond the per-key rate limit you set yourself.

= Is it secure? Can the AI break my site? =

It is built so a mistake can be reversed. Keys are hashed and shown once, requests are rate-limited, destructive actions need confirmation, changes can be previewed with a dry run, and every action is written to an audit log. Content, options, users, media deletions, menus, terms, comments, WooCommerce objects, SEO meta, revisions, events, Gutenberg and Site Editor edits, Beaver Builder and SiteOrigin layouts, search-replace runs and plugin or theme installs, updates and deletions are journaled and can be undone individually or as a batch - seven days by default - and database checkpoints roll every site table back to an earlier moment. Things with no inverse - a sent email, a cache flush, an arbitrary WP-CLI command, an outbound HTTP request - are recorded as not undoable rather than pretended otherwise; take a checkpoint first when you ask for those.

= Can I limit what an AI agent is allowed to do? =

Yes. Every API key and every OAuth connection carries a scope: full access, read-only, or a custom list of allowed tools, chosen when the key is created or the connection is approved. Safe mode adds confirmation for destructive tools on top, and the most powerful operations stay locked behind Power mode, which only an administrator can enable in wp-admin.

= Do I need to be a developer? =

No. Publishing content and running your store work through plain conversation. The developer tools (WP-CLI, files, database) are there when you want them, and gated behind safe mode until you say go.

= Which AI agents and assistants work with it? =

Any MCP-compatible client over Streamable HTTP - including Claude Code, the Claude desktop and web apps, ChatGPT, Cursor, VS Code with GitHub Copilot, Windsurf, Cline, Zed, Codex CLI, Gemini CLI, Opencode and n8n.

= Does it work with WooCommerce? =

Yes. When WooCommerce is active, 40 store tools light up: products and variations, orders and refunds, coupons, customers, stock, shipping zones, tax rates, payment gateways, and sales reports - so your agent can run the store, not just describe it.

= Does it work with The Events Calendar? =

Yes. When The Events Calendar is active, your agent can create and edit events, venues and organizers, and trash or delete events (venues and organizers are removed with the standard delete-post tool). With Events Calendar Pro it can also manage recurring events - edit a whole series or cancel a single date. Editing one occurrence on its own stays in wp-admin. Changes are undoable, and permanent deletes take a database checkpoint first.

= Does it work on a local development site (Local, Studio, MAMP, DevKinsta)? =

Yes. Terminal tools like Claude Code, Cursor, Codex and Gemini CLI run on the same computer as your local site, so they connect with an API key exactly like on a live site - no public URL needed. Claude Desktop connects through a small local bridge (`mcp-remote`); the Connections tab detects local sites and shows the ready-to-copy config. Only the cloud-side apps - claude.ai and ChatGPT - require a public HTTPS address, because they connect from the vendor's servers; a tunnel works for temporary testing, but be aware it exposes your whole dev site while it runs.

= My host or Jetpack already offers MCP. Do I need this? =

Hosting-level MCP servers mostly manage the hosting account - sites, DNS, backups - and WordPress.com's MCP reaches a self-hosted site only through a paid Jetpack plan. Cowboy MCP works inside the site itself, on any host, for free: content, WooCommerce, users, plugins, diagnostics, with undo and an audit log. They can run side by side.

= Should I wait for the official MCP Adapter? =

You don't need to. The Abilities API (WordPress 6.9+) and the official MCP Adapter are a framework: plugins register abilities, and the adapter exposes whatever was registered. Cowboy MCP is a complete, turnkey MCP server with its own toolset, safety layer, undo, audit log, scoping, OAuth connector and Connection Doctor, and it runs on WordPress 6.2 or later without Composer or Node.js. On 6.9+ it registers its tools as abilities, so the adapter can use them too - you can run both side by side.

= How does it compare with Novamira? =

Novamira is built for development and staging copies and gives the agent a PHP shell. Cowboy MCP is built for live sites: typed tools, safe mode, undo, database checkpoints and an audit log, all free, installed from WordPress.org. See the full [Cowboy MCP vs Novamira comparison](https://cowboymcp.com/compare/cowboy-mcp-vs-novamira).

= Can I vibe code my WordPress site? =

Yes - that is what it is built for. Your agent can write and edit theme and plugin code in `wp-content`, run WP-CLI, and read the error log to fix what it broke. Every PHP file is syntax-checked before it lands, and `mu-plugins` (which WordPress recovery mode cannot pause) stays off-limits unless an administrator turns on Power mode - so a cut-off or broken payload cannot take the site down. Take a database checkpoint first (or let auto-checkpoint do it), and every change lands in the undo journal - so vibe coding a live site does not have to be a leap of faith.

= Does it send my data anywhere? =

No telemetry and no phone-home - your data stays on your server. The only outbound connections are to WordPress.org, when your agent installs or updates plugins and themes, plus any HTTP requests you explicitly ask your agent to make. There is no hosted relay and no account with us; your AI client connects to your site directly.

= Does it work with the WordPress Abilities API? =

Yes, both ways, on WordPress 6.9 or newer. Every allowed tool is registered as a `cowboy-mcp/*` ability, so `wp ability run`, the core Abilities REST endpoint, the official MCP Adapter and the AI plugin's Abilities Explorer all reach it - and every call still goes through safe mode, dry run, the audit log and the undo journal. Callers need an administrator account; `wp_cli` and `wp_write_file` are only exposed while Power mode is on. In the other direction, abilities registered by other plugins appear as tools for your agent; they run their own permission checks and are not undoable. Both directions have a switch under Settings. Request formats and examples: [Cowboy MCP 1.6.4 release notes](https://cowboymcp.com/news/cowboy-mcp-1-6-4).

= Does it work on multisite? =

Cowboy MCP is built for single sites and is not network-aware. On a multisite network, activate it on each site you want an agent to manage (not network-wide) and generate keys on that site; its plugin tools do recognise network-activated plugins when activating or deactivating.

= Connection problems: 401/404, "registration is closed", a refused redirect host, or a connector that stopped after a staging sync =

Start with the **Connection Doctor** on the Connections tab. It tests HTTPS, the REST API, OAuth discovery and common host blockers (Cloudflare bot rules, ModSecurity-style firewalls, LiteSpeed caching of `/wp-json/`), names the exact problem and gives you a fix. The most common cases:

* **"Registration is closed"** - new AI apps can register only while "New connections" is on: on the Connections tab pick the app ("Connect another app" if the list is not shown), click "Enable for 30 minutes", then add the connector.
* **Connector stopped after a staging sync or database restore** - click "Enable for 30 minutes", then Reconnect in the AI app and approve again. Nothing needs removing.
* **"Not an allowed redirect host"** - the connector only returns approvals to known AI apps and localhost; add another OAuth client's host (for example a self-hosted n8n) under Desktop Connector > Additional allowed hosts.
* **Connected, but "no tools"** - the agent sees two gateway tools and discovers the rest on demand; ask it to "discover tools for WooCommerce". If even those are missing, check the key's scope.

Still stuck? Paste the Doctor report into a new topic in the [support forum](https://wordpress.org/support/plugin/cowboy-mcp/) - topics are usually answered within a day.

== Screenshots ==

1. Connections tab - pick your AI tool (Claude Code, Claude Desktop, claude.ai, ChatGPT, Cursor, Codex, and more) and copy the ready-made setup command. Connected apps - API keys and app connections - are listed in one table with when each was created and last used, and can be limited to chosen tools or revoked.
2. Settings tab - turn the server on or off, require Safe Mode confirmation for destructive actions, enable the one-click Desktop/web (OAuth) connector, set a per-key rate limit, tune the undo journal and database checkpoints, and opt in to advanced Power Mode.
3. Activity tab - a per-change undo journal: review every change your agents made and roll any of them back individually, plus one-click database checkpoints you can restore the whole site to.
4. Logs tab - a structured, filterable audit log of every MCP tool call, error, and auth event, auto-pruned after 30 days.
5. About tab - what Cowboy MCP does, with links to the project site and source.
6. One-click browser sign-in - the connector opens a consent screen on your own site that names where you will be sent next and lets you choose full or read-only access; no access is granted until you approve it as an administrator.

== Changelog ==

= 1.7.0 =
* New: Beaver Builder - list builder pages, read a page layout and replace it (preview the node-by-node changes with a dry run), browse modules and their settings, edit global settings. Changes are undoable.
* New: SiteOrigin Page Builder - read layouts and edit them whole or row by row and widget by widget, apply prebuilt layouts, browse widgets and their settings, edit Page Builder settings, activate Widgets Bundle widgets. Changes are undoable. Editing needs Page Builder 2.36.0 or later.
* New: a redesigned settings page. Connections lists every API key and app connection in one table with when it was created and last used; Activity filters the change journal by connection, status or text and links each undo to the change it reverted; confirmations happen on the page; Settings saves from one bar. Works on phones and in right-to-left languages.
* New: an Add to Claude button on the claude.ai and Claude Desktop panels opens Claude with your site already filled in - no link to copy. Shown on public HTTPS sites, the only ones Claude can connect to.
* Improved: Connection Doctor results are shown in your language (the copied report stays in English for support threads).
* Fix: undoing a post change no longer strips backslashes from page-builder data stored in post meta.
* Fix: the connection approval page no longer shows a PHP deprecation notice on sites with debugging turned on.

= Earlier versions =

For the changelog of earlier releases, see [changelog.txt](https://plugins.svn.wordpress.org/cowboy-mcp/trunk/changelog.txt).
