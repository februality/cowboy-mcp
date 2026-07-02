# Cowboy MCP 🤠 — WordPress MCP Server for AI Coding Agents

Cowboy MCP is a WordPress plugin that turns any WordPress site into a [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server over Streamable HTTP, so AI coding agents like **Claude Code**, **Codex**, and **Cursor** can manage it — in plain English.

![Version](https://img.shields.io/badge/version-1.4.0-34ff7a)
![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)

**Website:** [cowboymcp.com](https://cowboymcp.com) · **Download:** [WordPress.org plugin directory](https://wordpress.org/plugins/cowboy-mcp/)

Connect any MCP client that speaks Streamable HTTP over a single authenticated endpoint and manage posts, pages, plugins, themes, users, media, WooCommerce, and much more.

*New in 1.4.0: a one-click OAuth connector for the Claude desktop & web apps — connect with no terminal and no API key. Now available in the WordPress.org plugin directory.*

---

## Why Cowboy MCP?

- **Built for coding agents — and now the Claude apps** — terminal workflows with Claude Code, Codex, Cursor, and any Streamable HTTP MCP client, plus a one-click OAuth connector for the Claude desktop & web apps (no terminal, no API key to paste).
- **No Node.js proxy** — the MCP endpoint is served natively from WordPress. Unlike adapter-based approaches that run a separate Node bridge to expose remote HTTP, there's nothing extra to install or keep running.
- **121 tools across core + popular plugins** — full content CRUD plus deep integrations for WooCommerce, ACF, Elementor, Wordfence, and cache plugins.
- **Context-efficient tool gateway** — instead of dumping 121 tool schemas into your agent's context window, the server fronts them with two meta-tools (`cowboy_discover` / `cowboy_run`); the agent searches for what it needs and runs it on demand.
- **Secure by default** — bcrypt-hashed API keys (shown once), per-key rate limiting, safe mode for destructive operations, and an always-on audit log.
- **Zero dependencies** — native WordPress APIs, no Composer, no npm, no build step. Works even on hosts without WP-CLI or `shell_exec()`.

## Highlights

- **Single REST endpoint** — JSON-RPC 2.0 over Streamable HTTP at `/wp-json/cowboy-mcp/v1/endpoint` (MCP `2025-06-18` spec)
- **Two ways to connect** — a Bearer-token HTTP endpoint for terminal agents, or a one-click **OAuth custom connector** for the Claude desktop & web apps (RFC 8414 / 9728 discovery, Dynamic Client Registration, admin consent; off by default)
- **121 tools behind a gateway** — full CRUD for posts, pages, CPTs, taxonomies, comments, options, users, media, plus database queries, WP-CLI, diagnostics, and conditional tools for popular plugins — surfaced through `cowboy_discover` / `cowboy_run` so they don't flood the agent's context
- **17 read-only resources**, **4 resource templates**, and **8 workflow prompts** with argument auto-completion
- **Secure by default** — bcrypt-hashed API keys, per-key rate limiting, safe mode for destructive operations, and an always-on audit log
- **Zero dependencies** — no Composer, no npm, no build step, no CDN. Native WordPress APIs, so it works even on hosts without WP-CLI or `shell_exec()`.

## Requirements

- WordPress **6.2+**
- PHP **8.0+**

## Installation

Install straight from the WordPress.org plugin directory:

1. In WordPress: **Plugins → Add New**, search for **Cowboy MCP**, and click **Install Now**.
2. **Activate** the plugin.
3. Go to **Settings → Cowboy MCP** and click **Generate API Key**. Copy the key — it is shown only once.

Updates arrive automatically through the normal WordPress updates screen, served by WordPress.org.

## Connecting an agent

Cowboy MCP offers two connection styles: a **Bearer-token endpoint** for terminal coding agents, and a **one-click OAuth connector** for the Claude desktop & web apps. The admin **Connection** tab walks you through whichever you pick.

### Claude Code (terminal)

```bash
claude mcp add --transport http wordpress \
  https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint \
  --header "Authorization: Bearer YOUR_API_KEY"
```

### Codex (terminal)

Set the key as an environment variable:

```bash
export COWBOY_MCP_API_KEY="YOUR_API_KEY"
```

Then add to `~/.codex/config.toml`:

```toml
[mcp_servers.wordpress]
url = "https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint"
bearer_token_env_var = "COWBOY_MCP_API_KEY"
```

Any MCP client supporting Streamable HTTP with a Bearer token works the same way.

### Claude desktop & web app (one-click OAuth)

No terminal and no API key required. Enable it once under **Settings → Cowboy MCP → Settings → Desktop Connector** (it's off by default), then:

1. In the Claude desktop app — or at `claude.ai` — open **Settings → Connectors** and click **Add custom connector**.
2. Paste your endpoint URL: `https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint`
3. Claude opens a sign-in page on *your* site. Review the request and click **Allow**.

The connector authenticates as the WordPress admin who approved it, and you can revoke any connected app from the same screen. Requires a public HTTPS site (Anthropic's cloud can't reach localhost) and a Claude **Pro, Max, Team, or Enterprise** plan.

## Capabilities

### Tools

Core tools are always available; plugin-integration tools register only when the matching plugin is active. To keep them from flooding an agent's context window, all tools are reached through two gateway meta-tools — `cowboy_discover` (search or browse by keyword/category) and `cowboy_run` (execute by name) — alongside a `wordpress://tools/catalog` resource that lists every tool with a one-line description. The counts below are the underlying tools available through that gateway.

| Domain | Tools | Examples |
|---|---:|---|
| **Core** | 42 | posts/pages/CPT CRUD, taxonomies, comments, plugins, themes, options, users, media, DB query/write, WP-CLI, search-replace, site info, site health, 9 diagnostics, batch execution, audit log |
| **WooCommerce** | 40 | products & variations, orders & refunds, customers, coupons, tax/shipping/gateway settings, sales reports |
| **Wordfence** | 17 | scans, IP/country blocks, firewall, live traffic, activity log, settings |
| **ACF** | 9 | field groups, field CRUD, repeater operations |
| **Elementor** | 7 | templates, page content, global styles, widgets |
| **Cache** | 4 | provider detect, flush, preload, settings (WP Rocket / LiteSpeed / W3TC) |
| **SEO** | 1 | provider detection (Yoast / Rank Math) |
| **Forms** | 1 | provider detection (WPForms / Gravity Forms / CF7) |
| **Total** | **121** | with every integration active |

### Resources

17 read-only resources (site info, recent posts, plugin/theme lists, WooCommerce summaries, Wordfence status, and more) plus 4 resource templates:

```
wordpress://posts/{id}
wordpress://options/{name}
wordpress://plugins/{slug}
wordpress://users/{id}
```

### Prompts

8 guided workflow prompts: `wordpress-site-audit`, `content-migration`, `seo-optimization`, `woocommerce-store-setup`, `troubleshoot-issue`, `bulk-content-update`, `security-hardening`, `performance-optimization`.

## FAQ

### What is a WordPress MCP server?

A WordPress MCP server exposes your site's management capabilities through the Model Context Protocol — the open standard AI agents use to call tools. Cowboy MCP implements one as a plugin: it serves a single Streamable HTTP endpoint that lets agents like Claude Code read, create, update, and delete WordPress content securely.

### How do I connect Claude Code to WordPress?

Install Cowboy MCP, generate an API key under **Settings → Cowboy MCP**, then run `claude mcp add --transport http wordpress https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint --header "Authorization: Bearer YOUR_API_KEY"`. Claude Code can then manage posts, plugins, themes, WooCommerce, and more by calling Cowboy MCP's tools over the MCP protocol.

### Can I connect the Claude desktop or web app without a terminal?

Yes. Enable the **Desktop Connector** once under **Settings → Cowboy MCP → Settings** (it's off by default), then in Claude open **Settings → Connectors → Add custom connector** and paste your endpoint URL. Claude sends you to a sign-in page on your own site; click **Allow** and you're connected — no API key to copy. It needs a public HTTPS site and a Claude Pro/Max/Team/Enterprise plan.

### Does Cowboy MCP work with WooCommerce?

Yes. When WooCommerce is active, Cowboy MCP registers 40 extra tools for products and variations, orders and refunds, customers, coupons, tax/shipping/gateway settings, and sales reports — using WooCommerce's own CRUD classes rather than raw SQL. Tools for ACF, Elementor, Wordfence, and cache plugins register automatically the same way.

### Do I need Node.js or WP-CLI?

No. Cowboy MCP serves the MCP endpoint natively from WordPress with zero external dependencies — no Node.js bridge, no Composer, no build step. It uses core WordPress APIs, so it works even on managed hosts that disable `shell_exec()` or lack WP-CLI. WP-CLI remains an optional power-user escape hatch when present.

### Is it safe to give an AI agent access to my site?

You're granting real control, so Cowboy MCP is built defensively. API keys are bcrypt-hashed and shown once, every request is rate-limited, safe mode requires explicit confirmation for destructive actions, and every tool call is recorded in an audit log. Sensitive options, dangerous SQL, and SSRF attempts are blocked by default.

### Which AI agents are supported?

Any MCP client that supports Streamable HTTP with a Bearer token — including Claude Code, Codex, and Cursor — point the agent at your site's URL and supply the API key. The **Claude desktop and web apps** can also connect with no terminal and no API key via the optional OAuth custom connector. More agents add MCP support regularly.

## Security

You are granting an AI agent significant control over your site. Cowboy MCP is built to keep that safe:

- **Authentication** — API keys are bcrypt-hashed (`wp_hash_password()`) and never stored in plain text. Keys are shown once at generation and can be revoked individually.
- **Rate limiting** — per-key, per-minute window (default 120/min).
- **Safe mode** *(on by default)* — destructive tools (delete posts, drop tables, write-mode WP-CLI, etc.) require an explicit `confirm: true`.
- **Dry run** — non-read-only tools accept a `dry_run` parameter to preview changes.
- **Audit log** — every tool call, error, and auth event is recorded in a database table with automatic 30-day pruning. Sensitive fields are redacted.
- **Guardrails** — option blocklist for sensitive settings, SQL blocklist for dangerous DDL, WP-CLI command blocklist, SSRF protection on outbound requests, `wp-content` path confinement for file ops, and self-delete protection.
- **OAuth connector** *(off by default)* — when enabled, access is granted only after an admin clicks **Allow** in a consent screen. Tokens are stored as SHA-256 hashes, carry an audience bound to your site (RFC 8707), and authenticate as the approving admin — if that user loses `manage_options`, the token fails closed. Enabling OAuth never lifts the settings/credential write-protection.

### Power mode

An **admin-only, opt-in** setting (off by default) that lifts a curated set of hard guardrails for advanced users (WP-CLI/SQL blocklists, sensitive-option writes, path confinement, SSRF protection). It can **only** be enabled by a human in `wp-admin` — the agent can never enable it through the API.

Power mode **never** lifts credential protections: writes to API keys / plugin settings and other credential options stay blocked, secret-touching DB queries stay blocked, result/secret redaction stays on, and self-delete protection remains.

## Configuration

Settings live under **Settings → Cowboy MCP** (`cowboy_mcp_settings` option):

| Setting | Default | Purpose |
|---|---|---|
| `enabled` | `true` | Master on/off switch for the MCP endpoint |
| `safe_mode` | `true` | Require `confirm: true` for destructive tools |
| `power_mode` | `false` | Lift curated guardrails (admin-only opt-in) |
| `allowed_tools` | `all` | Restrict which tools are exposed |
| `log_requests` | `false` | Also mirror audit entries to `error_log()` |
| `rate_limit` | `120` | Requests per key, per minute |
| `oauth_enabled` | `false` | Enable the OAuth custom connector for the Claude desktop & web apps |

## Updates

Cowboy MCP is distributed through the [WordPress.org plugin directory](https://wordpress.org/plugins/cowboy-mcp/), so updates arrive automatically through WordPress's native update system — new versions appear on your Plugins and Updates screens, with the usual per-plugin auto-update toggle.

## Extensibility

Two filters let you customize the tool surface:

```php
// Add custom tool definitions.
add_filter( 'cowboy_mcp_tools', function ( $tools ) { /* … */ return $tools; } );

// Block specific tools per request.
add_filter( 'cowboy_mcp_tool_allowed', function ( $allowed, $tool_name ) { /* … */ return $allowed; }, 10, 2 );
```

## Architecture

A single-endpoint MCP server built on the WordPress REST API. No autoloader — classes are required from the entry point; tools are split into lazily-loaded domain files under `includes/tools/`.

```
cowboy-mcp.php          Entry point — constants, requires, lifecycle hooks
includes/
  class-mcp-transport.php   REST route, JSON-RPC dispatch, sessions
  class-mcp-auth.php        API keys, Bearer validation, rate limiting
  class-mcp-oauth.php       OAuth 2.1 server (discovery, DCR, consent, tokens)
  class-mcp-tools.php       Tool registry + cowboy_discover/cowboy_run gateway, dispatch, dry-run/safe-mode gating
  class-mcp-resources.php   Read-only resources + templates
  class-mcp-prompts.php     Workflow prompts
  class-mcp-audit-log.php   DB-backed audit log
  class-mcp-security.php    Shared guardrails (blocklists, redaction, power mode)
  tools/                    Domain tool files (core, woocommerce, acf, …)
admin/
  class-mcp-admin.php       Settings page
```

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html). Cowboy MCP is free software.
