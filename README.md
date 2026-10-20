# Cowboy MCP 🤠 — Free WordPress MCP Server with Undo

![The Connections tab: pick your AI app, add the site with one click or a pasted link, and sign in through your browser](https://ps.w.org/cowboy-mcp/assets/screenshot-1.png)

▶ **[Watch the 90-second demo](https://www.youtube.com/watch?v=poFkhBgzq-s)**

Cowboy MCP is a free, open-source WordPress plugin that turns any WordPress site into a [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server over Streamable HTTP, so **Claude, ChatGPT, Gemini, Grok, Perplexity, Muse, Cursor, VS Code, Claude Code, Codex, n8n, OpenClaw, Hermes Agent** and any other MCP client can manage the site in plain English through **203 tools** — with per-change undo, database checkpoints and an audit log, so it can be trusted on a live site.

![Version](https://img.shields.io/badge/version-1.7.4-34ff7a)
![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)
![Tested](https://img.shields.io/badge/tested_up_to-7.1-21759b)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)
[![Glama score](https://glama.ai/mcp/servers/februality/cowboy-mcp/badges/score.svg)](https://glama.ai/mcp/servers/februality/cowboy-mcp)

**Install:** [WordPress.org plugin directory](https://wordpress.org/plugins/cowboy-mcp/) (one click, auto-updates) · **Try it in your browser:** [Live Preview](https://wordpress.org/plugins/cowboy-mcp/?preview=1) · **Website & guides:** [cowboymcp.com](https://cowboymcp.com) · **Connect your app:** [cowboymcp.com/connect](https://cowboymcp.com/connect) · **Questions:** [support forum](https://wordpress.org/support/plugin/cowboy-mcp/) · **Bugs:** [issues](https://github.com/februality/cowboy-mcp/issues)

---

## Why Cowboy MCP?

- **Every tool is free.** Up to **203 built-in tools** plus every ability your plugins register through the WordPress Abilities API — content, Gutenberg/Site Editor, WooCommerce, users, media, menus, plugins, themes, files, database, WP-CLI, diagnostics, revisions, SEO (Yoast, Rank Math, AIOSEO, SEOPress), The Events Calendar, ACF, Elementor, Beaver Builder, SiteOrigin, Wordfence, caching, forms — GPL-licensed, no Pro tier, no credits, no usage meter.
- **Every change is undoable.** A per-change undo journal (before-state snapshots, conflict detection, batch undo) plus one-click database checkpoints, with an always-on audit log. Plugin and theme updates take a file backup and a checkpoint first and auto-restore if the post-update health check fails.
- **Nothing in the middle.** The MCP endpoint runs inside your WordPress install. No hosted relay, no account, no telemetry — your AI client connects straight to your site.
- **Safe by default.** Safe mode (confirmation for destructive tools), dry run on every write tool, per-credential read-only/custom scoping, hashed keys shown once, per-key rate limits, denylists for sensitive options / dangerous SQL / WP-CLI commands, SSRF protection, path confinement to `wp-content`, and a Power mode only a human can enable in wp-admin.
- **One-click sign-in for every app.** Every app sends you to a sign-in page on your own site: Claude, ChatGPT, Gemini, Grok, Perplexity, Cursor and VS Code with one button or a pasted link, Claude Code, Codex, Gemini CLI and Muse Code with one command, Muse, OpenClaw and Hermes Agent from one pasted message, n8n through OAuth2. The consent screen names the app and lets you grant full access, read-only or a hand-picked list of tools; nothing is granted until you click **Approve**. API keys remain for scripts, CI and editors without browser sign-in (Windsurf, Cline, Zed).
- **Works locally too.** Local, Studio, MAMP, DevKinsta, wp-env: terminal tools connect with a key as on a live site; Claude Desktop connects through an `mcp-remote` bridge the Connections tab generates for you.
- **Context-efficient.** `tools/list` returns two gateway tools (`cowboy_discover`, `cowboy_run`); the agent discovers and runs the other tools on demand instead of loading 203 schemas into its context. On WordPress 6.9+ every tool is also a `cowboy-mcp/*` ability for WP-CLI, REST and the official MCP Adapter — with undo.
- **Zero dependencies.** Native WordPress APIs only — no Composer, no npm, no build step, no `wp-admin/includes` at request time. Works on hosts without WP-CLI or `shell_exec()`.

> "More access than any other MCP offers, easy to use, LOVE the change journal and the checkpoints — safe if you break something." — WordPress.org review

## Tool coverage

| Area | Tools | What the agent can do |
|---|---:|---|
| Content | 5 posts/pages/CPTs · 4 taxonomies · 4 comments · 4 media · 6 menus · 1 options | draft, edit, schedule, publish; upload media, fix alt text; build nav menus |
| Gutenberg & Site Editor | 15 (8 on classic themes) | read a page as a block tree and edit it by path; block types, patterns, FSE templates/parts, global styles, navigations |
| Site administration | 5 users · 6 plugins · 5 themes · 4 files · 5 database · 3 WP-CLI/system · 1 site health | install/update/delete plugins & themes safely, manage roles, edit files in `wp-content`, repair tables, run WP-CLI |
| Diagnostics | 10 | error log, HTTP & email tests, hooks, transients, REST routes, thumbnails, rewrite rules, snapshot, Connection Doctor |
| Safety | 6 rollback · 2 batch/audit | list & undo changes, create/list/restore/delete checkpoints, batch execution, audit-log retrieval |
| WooCommerce | 40 | products & variations, orders & refunds, customers, coupons, tax/shipping/payment settings, reports |
| Wordfence | 17 | scans, blocks, firewall, live traffic, activity, settings |
| ACF / Elementor | 9 / 7 | field groups, fields, repeaters / templates, page content, global styles, widgets |
| Beaver Builder / SiteOrigin | 7 / 12 | builder pages, layouts with dry-run diff, modules, global settings / layouts and row/widget edits, prebuilt layouts, widgets, settings, Widgets Bundle activation |
| Revisions / Events | 3 / 13 | list, diff & restore post revisions / The Events Calendar events, venues, organizers + Events Calendar Pro recurrence |
| SEO / Cache / Forms | 4 / 4 / 1 | Yoast, Rank Math, All in One SEO & SEOPress meta read/write/audit / WP Rocket, LiteSpeed, W3TC / WPForms, Gravity Forms, CF7 |

Most other page builders are supported too: a builder that keeps its layout in post content or post meta is edited through the standard post tools, with the same dry run and per-change undo.

Plus **17 read-only resources** (incl. `wordpress://tools/catalog`), **4 resource templates** (`wordpress://posts/{id}`, `wordpress://options/{name}`, `wordpress://plugins/{slug}`, `wordpress://users/{id}`) and **8 workflow prompts** with argument auto-completion. Integrations register only when their plugin is active.

## Requirements

- WordPress **6.2+** (tested up to 7.1)
- PHP **8.0+**
- HTTPS for the OAuth connector and for cloud clients (claude.ai, ChatGPT); plain HTTP is fine for terminal tools on a local site

## Installation

1. **Plugins → Add New**, search for **Cowboy MCP**, install and activate — or download from [WordPress.org](https://wordpress.org/plugins/cowboy-mcp/).
2. **Settings → Cowboy MCP → Generate API Key**. Copy it — it is shown once and stored hashed.
3. Connect your client (below). The **Connection** tab shows every snippet pre-filled with your site's endpoint.

Updates arrive through the normal WordPress updates screen.

## Connect your app

Every app signs in through a consent screen on your own site; no API key to paste. The full matrix — plan gates, whether a local site works, and the date each row was last verified end to end — is at [cowboymcp.com/connect](https://cowboymcp.com/connect).

| App | Plan needed | Button or command | Guide |
|---|---|---|---|
| Claude (claude.ai) | Any Claude plan; Free allows one custom connector. Team and Enterprise: an owner adds it first | Connections tab → **Add to Claude** | [connect-wordpress-to-claude](https://cowboymcp.com/connect-wordpress-to-claude) |
| Claude Desktop | Same as claude.ai | Connections tab → **Add to Claude**, or Settings → Connectors → Add custom connector | [connect-wordpress-to-claude-desktop](https://cowboymcp.com/connect-wordpress-to-claude-desktop) |
| Claude Code | A Claude plan that includes Claude Code (Pro or Max), or API billing | Connections tab → Claude Code panel → run two lines (`claude mcp add`, `claude mcp login`) | [connect-wordpress-to-claude-code](https://cowboymcp.com/connect-wordpress-to-claude-code) |
| ChatGPT | Business, Enterprise or Edu workspace, on the web; Pro connects one server read-only. No Developer mode | Connections tab → **Open ChatGPT**, then Plugins → Add → Add custom MCP server → Create as a plugin | [connect-wordpress-to-chatgpt](https://cowboymcp.com/connect-wordpress-to-chatgpt) |
| Codex CLI and app | A ChatGPT plan that includes Codex (Plus, Pro, Business, Enterprise or Edu), or an API key | Connections tab → Codex panel → one `codex mcp add` command | [connect-wordpress-to-codex](https://cowboymcp.com/connect-wordpress-to-codex) |
| Perplexity | Pro, Max or Enterprise. Free has no custom connectors | Connections tab → **Open Perplexity**, then Customize → Connectors → + Custom connector | [connect-wordpress-to-perplexity](https://cowboymcp.com/connect-wordpress-to-perplexity) |
| Grok | Any, including the free plan | Connections tab → **Open Grok**, then grok.com/connectors → New Connector → Custom | [connect-wordpress-to-grok](https://cowboymcp.com/connect-wordpress-to-grok) |
| Gemini app | A personal Google account in the US, 18+, Gemini in English; no paid plan listed | Connections tab → **Open Gemini**, then Settings → Connected Apps → Custom apps | [connect-wordpress-to-gemini](https://cowboymcp.com/connect-wordpress-to-gemini) |
| Gemini CLI | A Google account; the free tier works | Connections tab → Gemini CLI panel → one `gemini mcp add` command, then `/mcp auth` | [connect-wordpress-to-gemini](https://cowboymcp.com/connect-wordpress-to-gemini) |
| Muse (Meta) | Free; Meta offers Muse to adults in the United States | Connections tab → Muse panel → **Copy message**, paste it into a new Muse chat | [connect-wordpress-to-muse](https://cowboymcp.com/connect-wordpress-to-muse) |
| Muse Code | Same Muse account | Connections tab → Muse Code panel → **Copy message**, paste into `muse`, then one `muse mcp login` command | [connect-wordpress-to-muse#muse-code](https://cowboymcp.com/connect-wordpress-to-muse#muse-code) |
| Cursor | Any Cursor plan | Connections tab → **Add to Cursor** | [connect-wordpress-to-cursor](https://cowboymcp.com/connect-wordpress-to-cursor) |
| VS Code (GitHub Copilot) | Any Copilot plan, including Free | Connections tab → **Install in VS Code** | [connect-wordpress-to-vs-code](https://cowboymcp.com/connect-wordpress-to-vs-code) |
| n8n | Any n8n Cloud plan, or self-hosted n8n (Community Edition included) | Connections tab → n8n panel; add an **MCP Client Tool** node with an OAuth2 credential | [connect-wordpress-to-n8n](https://cowboymcp.com/connect-wordpress-to-n8n) |
| OpenClaw | A running OpenClaw gateway | Connections tab → OpenClaw panel → **Copy message**, paste into your OpenClaw chat; or `openclaw mcp add` + `openclaw mcp login` | [connect-wordpress-to-openclaw](https://cowboymcp.com/connect-wordpress-to-openclaw) |
| Hermes Agent | Hermes Agent by Nous Research; the chat path needs its connection tool, the terminal command works on every install | Connections tab → Hermes Agent panel → **Copy message**, paste into your Hermes chat; or one `hermes mcp add … --auth oauth` | [connect-wordpress-to-hermes-agent](https://cowboymcp.com/connect-wordpress-to-hermes-agent) |

Windsurf, Cline, Zed, LibreChat and any other Streamable HTTP client connect with a scoped API key (config blocks below); Opencode signs in with the same browser flow.

## Connecting an agent

The endpoint is `https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint` (JSON-RPC 2.0 over Streamable HTTP, MCP `2025-06-18`).

**Claude Code**

```bash
claude mcp add --transport http your-site https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint \
  --header "Authorization: Bearer YOUR_API_KEY"
```

**Claude desktop & web, ChatGPT (one-click, no key)** — click **Add to Claude** / **Open ChatGPT** on the Connections tab (browser sign-in is switched on for you; for ChatGPT paste the copied link as a custom MCP server), approve the consent screen on your site (choose full, read-only or custom access). Requires a public HTTPS site.

**Claude Desktop on a local site** — use the `mcp-remote` bridge config shown on the Connections tab:

```json
{
  "mcpServers": {
    "your-site": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "http://yoursite.local/wp-json/cowboy-mcp/v1/endpoint",
        "--header", "Authorization:${AUTH_HEADER}"],
      "env": { "AUTH_HEADER": "Bearer YOUR_API_KEY" }
    }
  }
}
```

**Cursor / Windsurf (Devin Desktop)** (`~/.cursor/mcp.json`, `~/.config/devin/mcp_config.json`)

```json
{
  "mcpServers": {
    "your-site": {
      "url": "https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint",
      "headers": { "Authorization": "Bearer YOUR_API_KEY" }
    }
  }
}
```

Cline: add `"type": "streamableHttp"` to the entry. VS Code (`.vscode/mcp.json`): use `servers` instead of `mcpServers` and add `"type": "http"`. Zed: put the same `url` + `headers` under `context_servers` in its settings.

**Codex CLI** — Codex reads the key each time it starts, so add the `export` to your shell profile too.

```bash
export COWBOY_MCP_API_KEY="YOUR_API_KEY"
codex mcp add your-site --url https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint --bearer-token-env-var COWBOY_MCP_API_KEY
```

**Gemini CLI**

```bash
gemini mcp add --scope user --transport http your-site https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint \
  --header "Authorization: Bearer YOUR_API_KEY"
```

**Gemini app, Grok, Perplexity, Meta Muse, OpenClaw, Hermes Agent, n8n (no key)** — each has its own panel on the Connections tab: the cloud apps take your connection link as a custom MCP server, Muse, OpenClaw and Hermes take a message you paste into their chat, and n8n's MCP Client Tool node uses OAuth2. All of them sign in on your site through the browser.

Any client that speaks Streamable HTTP with a Bearer header works the same way (n8n, Opencode, LibreChat, your own agent). Opencode: set `"oauth": false` on the remote server so it uses the key. Step-by-step guides per client: [cowboymcp.com](https://cowboymcp.com/guides).

**Quick smoke test with curl**

```bash
curl -s -X POST https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint \
  -H "Authorization: Bearer YOUR_API_KEY" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"cowboy_run","arguments":{"tool":"wp_site_info","arguments":{}}}}'
```

## Safety model

- **Safe mode** (default on): tools annotated `destructiveHint` refuse to run until the call is resent with `confirm: true`; the refusal includes a preview.
- **Dry run**: every non-read-only tool accepts `dry_run: true` and reports exactly what would change.
- **Undo journal**: before-state snapshots for journaled changes (posts, options, users, media, menus, terms, comments, WooCommerce objects, SEO meta, Gutenberg/FSE edits, search-replace rows, plugin/theme packages); `wp_list_changes` / `wp_undo_change`, batch undo, conflict detection, redo-on-undo; 7-day retention by default.
- **Database checkpoints**: prefix-scoped dump of the site's tables, atomic restore; up to 5 kept; taken automatically before plugin/theme updates and mutating WP-CLI commands. Checkpoints restore tables, not uploaded files or code.
- **Audit log**: every tool call, error and auth event in `{prefix}cowboy_mcp_audit_log` (key, tool, arguments, result, IP); pruned after 30 days; secrets redacted on read.
- **Scoped credentials**: API keys and OAuth connections carry `{mode: full|read_only|custom, allowed_tools[]}`; enforced at dispatch, also for tools called through `cowboy_run` and batches. `readOnlyHint` is treated as a security boundary.
- **Keys & limits**: keys stored as one-way hashes, shown once, revocable individually; per-key rate limit (120/min default); request `Origin` allowlist; OAuth tokens stored hashed, off by default, admin consent required.
- **Guardrails**: protected-option denylist (`siteurl`, `active_plugins`, credentials, the plugin's own settings…), dangerous-SQL and WP-CLI blocklists (`eval`, `shell`, `db drop`, …) applied on a shell-style tokenizer, SSRF validation on outbound requests, `wp-content` path confinement with atomic writes, a PHP syntax check before every file write and no `mu-plugins` writes without Power mode, self-delete and last-administrator protection.
- **Power mode**: an admin-only checkbox that lifts the curated guardrails for one-off jobs; it can never be enabled through the API, and it never lifts credential-option protection, secret redaction or self-protection.
- **Connection Doctor**: one-click self-test (HTTPS, reachability, REST, OAuth discovery, host blockers such as Cloudflare challenges, ModSecurity-style WAFs, LiteSpeed caching) with fingerprinted causes and fixes; also `wp cowboy-mcp doctor` and the `wp_connection_doctor` tool.

## How it compares

Factual, dated comparisons live on the site: [all WordPress MCP plugins compared](https://cowboymcp.com/compare) · [vs Novamira](https://cowboymcp.com/compare/cowboy-mcp-vs-novamira) · [vs AI Engine](https://cowboymcp.com/compare/cowboy-mcp-vs-ai-engine) · [vs WPVibe](https://cowboymcp.com/compare/cowboy-mcp-vs-wpvibe) · [vs InstaWP](https://cowboymcp.com/compare/cowboy-mcp-vs-instawp) · [vs the WordPress MCP Adapter](https://cowboymcp.com/compare/cowboy-mcp-vs-wordpress-mcp-adapter) · [self-hosted vs hosted](https://cowboymcp.com/compare/self-hosted-vs-hosted-wordpress-mcp). Short version: the endpoint is self-hosted with no relay or metering, every tool is free, and undo + checkpoints + audit log ship together.

## Architecture

```
cowboy-mcp.php                 # entry point, constants, activation/uninstall
includes/
  class-mcp-transport.php      # REST route, JSON-RPC dispatch, sessions (Streamable HTTP)
  class-mcp-auth.php           # API keys (hashed), Bearer validation, rate limits, Origin allowlist
  class-mcp-oauth.php          # OAuth 2.1 authorization server (discovery, DCR, consent, tokens)
  class-mcp-security.php       # denylists, SSRF, SQL/WP-CLI gates, scoping, secret scrubbing
  class-mcp-tools.php          # registry, gateway meta-tools, dispatch, lazy domain loading
  class-mcp-rollback.php       # undo journal   class-mcp-checkpoint.php  # DB checkpoints
  class-mcp-audit-log.php      # audit table    class-mcp-installer.php   # WP.org package installer
  class-mcp-doctor.php         # Connection Doctor   class-mcp-compat.php  # admin-free reimplementations
  class-mcp-resources.php / class-mcp-prompts.php / class-mcp-completion.php
  tools/{core,gutenberg,acf,woocommerce,seo,forms,cache,elementor,wordfence}/
admin/                         # settings page (Connection, Settings, Activity, Logs, About), assets
languages/                     # 12 bundled locales for the admin UI (ru, uk, zh_CN, ja, ko, es, fr, de, pt_BR, it, hi, id)
```

Tool descriptions and error messages returned to agents are intentionally English; the admin UI is translated.

### Extensibility

- `cowboy_mcp_tools` filter — register your own tool definitions and handlers.
- `cowboy_mcp_tool_allowed` filter — block specific tools per request.
- `cowboy_mcp_allowed_origins` filter — extend the request `Origin` allowlist.

## Development

```bash
npx wp-env start          # local WordPress at http://localhost:8890 with this checkout mounted as the plugin
CLI="$(docker container ls -q --filter 'name=^[0-9a-f]+-cli-1$')"
docker exec "$CLI" wp plugin check cowboy-mcp --format=csv   # Plugin Check (install it in wp-env first)
find . -name '*.php' -not -path './node_modules/*' -exec php -l {} +  # syntax check
```

No build step. Pull requests welcome — keep the WordPress.org review invariants (no `wp-admin/includes` requires, no path constants, prepared/validated `$wpdb` queries, escaped output).

## Support, reviews, security

- **Questions and connection problems:** the [WordPress.org support forum](https://wordpress.org/support/plugin/cowboy-mcp/) — paste your Connection Doctor report; topics are usually answered within a day.
- **Bugs and feature requests:** [GitHub issues](https://github.com/februality/cowboy-mcp/issues).
- **Security issues:** please report privately through this repository's Security tab (private vulnerability reporting) rather than a public issue.
- **Reviews:** if Cowboy MCP saves you time, a [review on WordPress.org](https://wordpress.org/support/plugin/cowboy-mcp/reviews/#new-post) helps other site owners find it.

## License

GPL-2.0-or-later. © Andrew Ivanov ([februality](https://profiles.wordpress.org/februality/)).
