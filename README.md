# Cowboy MCP 🤠 — Free WordPress MCP Server with Undo

Cowboy MCP is a free, open-source WordPress plugin that turns any WordPress site into a [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server over Streamable HTTP, so **Claude, ChatGPT, Cursor, Claude Code, Codex, Gemini** and any other MCP client can manage the site in plain English — with per-change undo, database checkpoints and an audit log, so it can be trusted on a live site.

![Version](https://img.shields.io/badge/version-1.6.3-34ff7a)
![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)
![Tested](https://img.shields.io/badge/tested_up_to-7.1-21759b)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)

**Install:** [WordPress.org plugin directory](https://wordpress.org/plugins/cowboy-mcp/) (one click, auto-updates) · **Try it in your browser:** [Live Preview](https://wordpress.org/plugins/cowboy-mcp/?preview=1) · **Website & guides:** [cowboymcp.com](https://cowboymcp.com) · **Questions:** [support forum](https://wordpress.org/support/plugin/cowboy-mcp/) · **Bugs:** [issues](https://github.com/februality/cowboy-mcp/issues)

---

## Why Cowboy MCP?

- **Every tool is free.** Up to **168 tools** — content, Gutenberg/Site Editor, WooCommerce, users, media, menus, plugins, themes, files, database, WP-CLI, diagnostics, SEO, ACF, Elementor, Wordfence, caching, forms — GPL-licensed, no Pro tier, no credits, no usage meter.
- **Every change is undoable.** A per-change undo journal (before-state snapshots, conflict detection, batch undo) plus one-click database checkpoints, with an always-on audit log. Plugin and theme updates take a file backup and a checkpoint first and auto-restore if the post-update health check fails.
- **Nothing in the middle.** The MCP endpoint runs inside your WordPress install. No hosted relay, no account, no telemetry — your AI client connects straight to your site.
- **Safe by default.** Safe mode (confirmation for destructive tools), dry run on every write tool, per-credential read-only/custom scoping, hashed keys shown once, per-key rate limits, denylists for sensitive options / dangerous SQL / WP-CLI commands, SSRF protection, path confinement to `wp-content`, and a Power mode only a human can enable in wp-admin.
- **Two ways to connect.** A Bearer-token endpoint for terminal agents and editors, and a one-click OAuth 2.1 connector (admin consent, scope choice) for the Claude desktop/web apps and ChatGPT.
- **Works locally too.** Local, Studio, MAMP, DevKinsta, wp-env: terminal tools connect with a key as on a live site; Claude Desktop connects through an `mcp-remote` bridge the Connection tab generates for you.
- **Context-efficient.** `tools/list` returns two gateway tools (`cowboy_discover`, `cowboy_run`); the agent discovers and runs the other tools on demand instead of loading 168 schemas into its context.
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
| SEO / Cache / Forms | 4 / 4 / 1 | Yoast & Rank Math meta read/write/audit / WP Rocket, LiteSpeed, W3TC / WPForms, Gravity Forms, CF7 |

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

## Connecting an agent

The endpoint is `https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint` (JSON-RPC 2.0 over Streamable HTTP, MCP `2025-06-18`).

**Claude Code**

```bash
claude mcp add --transport http your-site https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint \
  --header "Authorization: Bearer YOUR_API_KEY"
```

**Claude desktop & web, ChatGPT (one-click, no key)** — turn on **Settings → Cowboy MCP → Settings → Desktop Connector**, add the endpoint as a custom connector in the app, approve the consent screen on your site (choose full, read-only or custom access). Requires a public HTTPS site.

**Claude Desktop on a local site** — use the `mcp-remote` bridge config shown on the Connection tab:

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

**Cursor / Windsurf / Cline / Zed / VS Code** (e.g. `~/.cursor/mcp.json`)

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

**Codex CLI**

```bash
export COWBOY_MCP_API_KEY="YOUR_API_KEY"
codex mcp add your-site --url https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint --bearer-token-env-var COWBOY_MCP_API_KEY
```

**Gemini CLI**

```bash
gemini mcp add --transport http your-site https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint \
  --header "Authorization: Bearer YOUR_API_KEY"
```

Any client that speaks Streamable HTTP with a Bearer header works the same way (n8n, Opencode, LibreChat, your own agent). Step-by-step guides per client: [cowboymcp.com](https://cowboymcp.com/guides).

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
- **Guardrails**: protected-option denylist (`siteurl`, `active_plugins`, credentials, the plugin's own settings…), dangerous-SQL and WP-CLI blocklists (`eval`, `shell`, `db drop`, …) applied on a shell-style tokenizer, SSRF validation on outbound requests, `wp-content` path confinement with atomic writes, self-delete and last-administrator protection.
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
