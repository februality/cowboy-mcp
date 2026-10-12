=== Cowboy MCP - Free MCP Server with Undo for Claude & ChatGPT ===
Contributors: februality
Donate link: https://cowboymcp.com/tip
Tags: mcp, ai-agent, model-context-protocol, claude, claude-code
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.7.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude, ChatGPT, Gemini, Cursor & any AI agent to your site. Free, secure WordPress MCP server (Model Context Protocol). Undo any change.

== Description ==

Cowboy MCP connects your WordPress site to Claude, ChatGPT, Cursor, Gemini or any other AI agent, so your AI assistant can run the site for you - and you can undo anything it changed. It is a free, open-source, secure **WordPress MCP server** (Model Context Protocol) that runs inside your own site: no relay, no account, no Pro tier. The agent talks straight to your site.

**Stop clicking around wp-admin. Just tell your AI what you want.**

* 💬 **Do everything by chat** - create and edit posts and pages, run your WooCommerce shop, manage users and menus, tidy the media library, change settings, and fix errors when they crop up.
* ↩️ **Undo any change** - a per-change undo journal and one-click database checkpoints let you roll back a single edit or the entire site.
* 🔄 **Updates plugins & themes safely** - backup first, health check after, one-command undo if anything breaks.
* 🛡️ **Secure by default** - safe mode confirms before anything destructive, you can preview any change before it runs, every key can be scoped to read-only, and every action is written to an audit log.
* 🆓 **Free and open source** - every tool included, with deep WooCommerce, Gutenberg, ACF, Elementor, Beaver Builder, SiteOrigin, Wordfence and SEO support. No Pro tier, no credits, no usage meter.
* 🔒 **Your data stays yours** - self-hosted, no accounts, no relay, no phone-home. Your agent connects to your site; nothing leaves your server.
* 🔌 **One-click sign-in** - Claude, ChatGPT, Gemini, Grok, Perplexity, Cursor, VS Code, Claude Code, Codex, n8n and any MCP client connect through your browser; no API key to paste. About two minutes, on a live site or a local one.

> "More access than any other MCP offers, easy to use, LOVE the change journal and the checkpoints - safe if you break something, without having to run back ups on the host or yet another plugin." - a WordPress.org reviewer

Ask in plain language - "publish these three drafts," "why is the checkout page 500-ing," "bump every Summer Sale price 20%," "clear the cache and re-check site health" - and your agent gets it done. Most WordPress AI plugins let an assistant write blog posts; Cowboy MCP lets your agent actually *run the site*, including WP-CLI, files, the database, error logs and diagnostics.

Try it without installing: click **Live Preview** above for a throwaway WordPress Playground site with Cowboy MCP active.

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
* **Works on any host** - shared hosting, managed WordPress, a VPS or a local dev site. Nothing to install beyond the plugin; no WordPress.com account or Jetpack plan.

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

= One-click sign-in for every app =

Every app sends you to a sign-in page on your own site, and you approve it as an administrator. Claude, ChatGPT, Cursor and VS Code: one button. Claude Code, Codex, Gemini CLI and Opencode: one command, then your browser opens. The consent screen names the app and lets you grant full access, read-only, or a hand-picked list of tools. Nothing is granted until you click **Approve**. Keys remain for scripts and CI.

= Connect Claude Code to WordPress =

Open the Claude Code panel on the Connections tab and run the two lines it shows:

    claude mcp add --transport http your-site https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint
    claude mcp login your-site

Headless or CI? Use an API key with `--header`.

= Connect Claude to WordPress (claude.ai and Claude Desktop) =

No terminal: click **Add to Claude** on the Connections tab and approve the sign-in on your own site. Needs a public HTTPS site.

= Connect ChatGPT to WordPress =

ChatGPT connects with the same one-click sign-in. You need ChatGPT on the web with a Plus, Pro, Business, Enterprise or Edu plan, and a public HTTPS site.

1. On your site, open the ChatGPT panel on the Connections tab and click **Open ChatGPT** (it copies your connection link).
2. In ChatGPT, open **Settings > Security and login** and turn on **Developer mode**. On Business and Enterprise workspaces an admin has to allow Developer mode first.
3. Go to **Plugins**, click **+** and choose **Add custom MCP server**. Paste your connection link as the MCP server URL and choose **OAuth**.
4. ChatGPT opens a sign-in page on your site. Review it and click **Approve**.
5. In a chat, open the **+** menu, pick **Developer mode** and select your site.

= Connect Perplexity, Grok and Gemini =

All three add a custom MCP server by URL and sign in with OAuth: click your app's **Open** button on the Connections tab (it copies your connection link and turns on new connections for 30 minutes), paste the link where the app asks for a server URL, then approve the sign-in on your own site.

* **Perplexity** (Pro, Max or Enterprise plans): Customize → Connectors → + Custom connector → Add MCP connector.
* **Grok** (free plan works): grok.com/connectors → New Connector → Custom.
* **Gemini app** (personal US Google account): Settings → Connected Apps → Custom apps → Add a custom app link.

All three connect from the vendor's cloud and need a public HTTPS site.

= Connect n8n =

Add an **MCP Client Tool** node: Endpoint = your connection link, Server Transport = HTTP Streamable, Authentication = **Bearer Auth** with an API key from the Connections tab, or **OAuth2** (n8n registers itself and opens the sign-in; self-hosted n8n adds its host under Settings → Additional allowed hosts). Execute the node once: its Tools output lists cowboy_discover and cowboy_run.

= Connect Cursor and VS Code =

Click **Add to Cursor** or **Install in VS Code** on the Connections tab, then sign in in your browser.

= Windsurf, Cline, Zed and other editors =

Add one block to your editor's MCP config, then restart the editor:

    {
      "mcpServers": {
        "your-site": {
          "url": "https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint",
          "headers": { "Authorization": "Bearer YOUR_API_KEY" }
        }
      }
    }

Cline adds `"type": "streamableHttp"`; Zed takes `url` and `headers` under `context_servers`.

= Connect Codex CLI =

    codex mcp add your-site --url https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint

Codex opens your browser to sign in. Headless or CI? Add `--bearer-token-env-var COWBOY_MCP_API_KEY` and export your key.

= Connect Gemini CLI =

    gemini mcp add -s user -t http your-site https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint

Click **Copy** on the Connections tab, then start `gemini` and run `/mcp auth your-site` to sign in. Headless or CI? Use an API key with `--header`.

= n8n, Opencode and other MCP clients =

Cowboy MCP is a standard Streamable HTTP MCP server (JSON-RPC 2.0). Point any MCP client - n8n, Opencode, LibreChat, your own agent - at `/wp-json/cowboy-mcp/v1/endpoint` with an `Authorization: Bearer` header. Opencode signs in with OAuth; set `"oauth": false` only for a key.

= Local development sites =

Local, Studio, MAMP, DevKinsta, wp-env, Docker - it works the same. Terminal and IDE tools sign in on this computer with no public URL; Claude Desktop uses the `mcp-remote` bridge. Cloud apps such as claude.ai and ChatGPT need a public HTTPS address.

= Secure by design: built for live sites =

* **Safe mode** (on by default) - destructive tools refuse to run until the agent confirms.
* **Dry run** - every non-read-only tool can report what would change without touching anything.
* **Per-change undo journal** - before-state snapshots; roll back one edit from the Activity tab or by asking your agent (seven days by default).
* **Database checkpoints** - one-click database snapshots, taken automatically before plugin and theme updates and mutating WP-CLI commands (tables only, not uploads or code).
* **Safe plugin and theme updates** - file backup and database checkpoint first, then a health check that restores the previous version if the site breaks.
* **Audit log** - every tool call, error and authentication event on the Logs tab; pruned after 30 days.
* **AI agent permissions** - each API key and OAuth connection can be full access, read-only, or a custom list of tools.
* **Hashed keys, rate limits, origin checks** - keys are shown once and stored as one-way hashes, requests are rate-limited per key, and unknown browser origins are rejected.
* **Guardrails you cannot talk your way past** - a denylist of sensitive options, dangerous SQL and WP-CLI commands, SSRF protection, file writes confined to `wp-content`, and last-administrator protection. Power mode lifts some for rare jobs; only a human can switch it on in wp-admin.
* **Connection Doctor** - a one-click self-test that names what blocks a connection (HTTPS, REST, OAuth, Cloudflare, firewalls, caching) and gives you a report to paste into a support topic.

= How Cowboy MCP is different =

* **Nothing in the middle.** Cowboy MCP runs on your server, on any host: no account, no relay, no credits, no quota.
* **Every tool is free.** Cowboy MCP ships plugins, themes, database and WooCommerce tools GPL-licensed, with no paid tier.
* **Typed tools with undo, not a PHP shell.** Tools that let an agent execute arbitrary PHP are built for development copies. Cowboy MCP gives the agent typed tools wrapped in safe mode, dry run, undo and checkpoints, so it can be trusted on the site that pays the bills. (Want the comparison? See [Cowboy MCP vs Novamira](https://cowboymcp.com/compare/cowboy-mcp-vs-novamira).)
* **A full safety net, not just an undo button.** A per-change journal with conflict detection, whole-database checkpoints, automatic restore of broken updates, dry run, safe mode and an always-on audit log.
* **A complete server today, wired into the official pieces.** The Abilities API and the official MCP adapter are a framework; Cowboy MCP is the turnkey server, and on WordPress 6.9+ it bridges both ways. Needs WordPress 6.2+, no Composer, Node.js or build step.

Guides and comparisons: [cowboymcp.com](https://cowboymcp.com).

= Works with =

Integrations that light up automatically: WooCommerce, Gutenberg and the Site Editor, Yoast SEO, Rank Math, All in One SEO, SEOPress, The Events Calendar (and Events Calendar Pro), Advanced Custom Fields (ACF), Elementor, Beaver Builder, SiteOrigin Page Builder and Widgets Bundle, Wordfence, WP Rocket, LiteSpeed Cache, W3 Total Cache, WPForms, Gravity Forms and Contact Form 7.

= External services =

Cowboy MCP sends no telemetry and makes no calls home. The only outbound connections are to WordPress.org, when your agent installs or updates plugins or themes, and any HTTP requests you explicitly ask your agent to make with the HTTP-request tool.

= Support and reviews =

Questions and connection problems: post in the [support forum](https://wordpress.org/support/plugin/cowboy-mcp/) and paste the Connection Doctor report - topics are usually answered within a day. Bugs and feature requests: [GitHub issues](https://github.com/februality/cowboy-mcp/issues). If Cowboy MCP saves you time, a [review](https://wordpress.org/support/plugin/cowboy-mcp/reviews/#new-post) helps other site owners find it.

== Installation ==

1. Install and activate Cowboy MCP from the Plugins screen.
2. Go to **Settings > Cowboy MCP**, open the **Connections** tab, pick your app and click its button or copy its command; you then sign in through your browser.
3. Connect your agent and start giving it instructions.

**Claude Code:**

    claude mcp add --transport http your-site https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint
    claude mcp login your-site

Headless or CI? Click **Generate API key** on the Connections tab (it is shown once - copy it) and add `--header "Authorization: Bearer YOUR_API_KEY"` to the first line instead of running the second.

**Claude desktop / web (no terminal):** click **Add to Claude** on the Connections tab (Claude opens with your site already filled in; browser sign-in is switched on for you) and approve with one click. (Requires a public HTTPS site; on a local site use the `mcp-remote` bridge shown on the Connections tab.)

**Every other client:** the Connections tab shows a ready-to-copy setup for each, and [cowboymcp.com](https://cowboymcp.com) has step-by-step guides.

== Frequently Asked Questions ==

= What is a WordPress MCP server? =

A plugin that lets AI assistants act on your site through the Model Context Protocol (MCP), the open standard Claude, ChatGPT, Cursor and other agents use to call external tools. Cowboy MCP turns your WordPress site into one of those tools, so your agent can publish, edit, update and fix things directly instead of telling you what to click.

= How do I connect ChatGPT to my WordPress site? =

Click **Open ChatGPT** on the ChatGPT panel of the Connections tab in Cowboy MCP; it copies your connection link. In ChatGPT on the web, turn on **Developer mode** under **Settings > Security and login**, then go to **Plugins**, click **+ > Add custom MCP server**, paste the link and choose OAuth. Approve the sign-in on your site as an administrator, then pick **Developer mode** from the **+** menu in a chat. It needs a Plus, Pro, Business, Enterprise or Edu plan and a public HTTPS site.

= How do I connect Claude to my WordPress site? =

For the Claude desktop and web apps, click **Add to Claude** on the Connections tab and approve the one-click sign-in - no terminal needed. For Claude Code, run the two lines shown ready to copy on the Connections tab and sign in in your browser - no key to copy, and no switch to flip: Claude Code and Codex identify themselves with a Client ID Metadata Document (the current MCP standard). For headless or CI use, generate an API key there instead.

= Is it free? =

Yes. Every tool, every integration and every safety feature is in this free plugin, licensed GPL-2.0. There is no Pro tier, no credit system and no usage cap beyond the per-key rate limit you set yourself.

= Is it secure? Can the AI break my site? =

It is built so a mistake can be reversed. Keys are hashed and shown once, requests are rate-limited, destructive actions need confirmation, changes can be previewed with a dry run, and every action is written to an audit log. Content, options, users, media deletions, menus, terms, comments, WooCommerce objects, SEO meta, revisions, events, Gutenberg and Site Editor edits, Beaver Builder and SiteOrigin layouts, search-replace runs and plugin or theme installs, updates and deletions are journaled and can be undone individually or as a batch - seven days by default - and database checkpoints roll every site table back to an earlier moment. Things with no inverse - a sent email, a cache flush, an arbitrary WP-CLI command, an outbound HTTP request - are recorded as not undoable rather than pretended otherwise; take a checkpoint first when you ask for those.

= Can I limit what an AI agent is allowed to do? =

Yes. Every API key and every OAuth connection carries a scope: full access, read-only, or a custom list of allowed tools, chosen when the key is created or the connection is approved. Safe mode adds confirmation for destructive tools on top, and the most powerful operations stay locked behind Power mode, which only an administrator can enable in wp-admin.

= Do I need to be a developer? =

No. Publishing content and running your store work through plain conversation. The developer tools (WP-CLI, files, database) are there when you want them, and gated behind safe mode until you say go.

= Which AI agents and assistants work with it? =

Any MCP-compatible client over Streamable HTTP - including Claude Code, the Claude desktop and web apps, ChatGPT, Perplexity, Grok, the Gemini app, Cursor, VS Code with GitHub Copilot, Windsurf, Cline, Zed, Codex CLI, Gemini CLI, Opencode and n8n.

= Does it work with WooCommerce? =

Yes. When WooCommerce is active, 40 store tools light up: products and variations, orders and refunds, coupons, customers, stock, shipping zones, tax rates, payment gateways, and sales reports - so your agent can run the store, not just describe it.

= Does it work with The Events Calendar? =

Yes. When The Events Calendar is active, your agent can create and edit events, venues and organizers, and trash or delete events (venues and organizers are removed with the standard delete-post tool). With Events Calendar Pro it can also manage recurring events - edit a whole series or cancel a single date. Editing one occurrence on its own stays in wp-admin. Changes are undoable, and permanent deletes take a database checkpoint first.

= Does it work on a local development site (Local, Studio, MAMP, DevKinsta)? =

Yes. Terminal tools like Claude Code, Cursor, Codex and Gemini CLI run on the same computer as your local site, so they sign in with OAuth in your browser - no public URL needed. An API key works too, for headless use. Claude Desktop connects through a small local bridge (`mcp-remote`); the Connections tab detects local sites and shows the ready-to-copy config. Only the cloud-side apps - claude.ai and ChatGPT - require a public HTTPS address, because they connect from the vendor's servers; a tunnel works for temporary testing, but be aware it exposes your whole dev site while it runs.

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
* **"Not an allowed redirect host"** - the connector only returns approvals to known AI apps and localhost; add another OAuth client's host (for example a self-hosted n8n) under Settings > Browser sign-in > Additional allowed hosts.
* **Connected, but "no tools"** - the agent sees two gateway tools and discovers the rest on demand; ask it to "discover tools for WooCommerce". If even those are missing, check the key's scope.

Still stuck? Paste the Doctor report into a new topic in the [support forum](https://wordpress.org/support/plugin/cowboy-mcp/) - topics are usually answered within a day.

== Screenshots ==

1. Connections tab - pick your AI tool (claude.ai, ChatGPT, Gemini, Grok, Perplexity, Claude Code, Codex, Cursor, VS Code, n8n, and more): one click adds the site to Claude or opens the other AI apps with your link copied, terminal tools get a ready-made command, and every app then signs in through your browser. Connected apps - API keys and app connections - are listed in one table with when each was created and last used, and can be limited to chosen tools or revoked.
2. Settings tab - turn the server on or off, require Safe Mode confirmation for destructive actions, turn on Browser sign-in (OAuth) for apps that connect without a key, set a per-key rate limit, tune the undo journal and database checkpoints, and opt in to advanced Power Mode.
3. Activity tab - a per-change undo journal: review every change your agents made and roll any of them back individually, plus one-click database checkpoints you can restore the whole site to.
4. Logs tab - a structured, filterable audit log of every MCP tool call, error, and auth event, auto-pruned after 30 days.
5. About tab - what Cowboy MCP does, with links to the project site and source.
6. One-click browser sign-in - the connector opens a consent screen on your own site that names where you will be sent next and lets you choose full or read-only access; no access is granted until you approve it as an administrator.

== Changelog ==

= 1.7.3 =
* New: connect Perplexity, Grok and the Gemini app from the Connections tab. One click copies your site link and opens the app, and the page confirms when the app has signed in. There is also an n8n panel with the MCP Client Tool fields ready to copy.
* New: `wp_search_replace` takes an optional `post_id` to limit a replacement to one post or page (contributed by dandry, PR #4).
* Fix: ChatGPT connects again. 1.7.2 refused ChatGPT's new sign-in identity with "the app's identity document on chatgpt.com is invalid".
* Fix: restoring a database checkpoint no longer runs out of memory on larger sites.
* Fix: the Wordfence tools work again on current Wordfence installs, and the activity report shows readable IP addresses.
* Fix: the WooCommerce customer report lists top spenders.
* Fix: an agent can no longer deactivate Cowboy MCP and cut off its own connection.
* Improved: "Last used" on the Connections tab stays accurate after an app's sign-in expires, and reconnecting an app you removed points you to the New connections switch.
* Security: `wp_cli` refuses commands that would print passwords, salts or keys and redacts them from its output; agents can no longer delete Cowboy MCP's own transients.
* Fixes: MCP protocol conformance (`completions` capability, JSON-RPC errors for malformed requests), PHP warnings when an agent creates a comment without an author name, and the access-level selector on phones.

= Earlier versions =

For the changelog of earlier releases, see [changelog.txt](https://plugins.svn.wordpress.org/cowboy-mcp/trunk/changelog.txt).
