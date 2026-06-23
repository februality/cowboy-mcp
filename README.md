# Cowboy MCP 🤠 — WordPress MCP Server for AI Coding Agents

Cowboy MCP is a WordPress plugin that turns any WordPress site into a [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server over Streamable HTTP, so AI coding agents like **Claude Code**, **Codex**, and **Cursor** can manage it — in plain English.

![Version](https://img.shields.io/badge/version-1.3.0-34ff7a)
![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)

**Website:** [cowboymcp.com](https://cowboymcp.com) · **Download:** [latest release](https://github.com/februality/cowboy-mcp/releases/latest)

Connect any MCP client that speaks Streamable HTTP over a single authenticated endpoint and manage posts, pages, plugins, themes, users, media, WooCommerce, and much more.

*Recently updated for WordPress.org plugin directory compliance.*

---

## Why Cowboy MCP?

- **Built for coding agents, not chat UIs** — designed for terminal-based workflows with Claude Code, Codex, Cursor, and any Streamable HTTP MCP client.
- **No Node.js proxy** — the MCP endpoint is served natively from WordPress. Unlike adapter-based approaches that run a separate Node bridge to expose remote HTTP, there's nothing extra to install or keep running.
- **121 tools across core + popular plugins** — full content CRUD plus deep integrations for WooCommerce, ACF, Elementor, Wordfence, and cache plugins.
- **Secure by default** — bcrypt-hashed API keys (shown once), per-key rate limiting, safe mode for destructive operations, and an always-on audit log.
- **Zero dependencies** — native WordPress APIs, no Composer, no npm, no build step. Works even on hosts without WP-CLI or `shell_exec()`.

## Highlights

- **Single REST endpoint** — JSON-RPC 2.0 over Streamable HTTP at `/wp-json/cowboy-mcp/v1/endpoint` (MCP `2025-06-18` spec)
- **121 tools** — full CRUD for posts, pages, CPTs, taxonomies, comments, options, users, media, plus database queries, WP-CLI, diagnostics, and conditional tools for popular plugins
- **17 read-only resources**, **4 resource templates**, and **8 workflow prompts** with argument auto-completion
- **Secure by default** — bcrypt-hashed API keys, per-key rate limiting, safe mode for destructive operations, and an always-on audit log
- **Self-hosted auto-updates** — new versions appear in your WordPress updates screen, served straight from GitHub Releases
- **Zero dependencies** — no Composer, no npm, no build step, no CDN. Native WordPress APIs, so it works even on hosts without WP-CLI or `shell_exec()`.

## Requirements

- WordPress **6.2+**
- PHP **8.0+**

## Installation

The plugin is distributed via GitHub Releases (not the WordPress.org directory).

1. Download **`cowboy-mcp.zip`** from the [latest release](https://github.com/februality/cowboy-mcp/releases/latest).
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the zip, and **Install Now**.
3. **Activate** the plugin.
4. Go to **Settings → Cowboy MCP** and click **Generate API Key**. Copy the key — it is shown only once.

Once installed, the plugin keeps itself up to date through the normal WordPress updates screen.

## Connecting an agent

### Claude Code

```bash
claude mcp add --transport http wordpress \
  https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint \
  --header "Authorization: Bearer YOUR_API_KEY"
```

### Codex

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

## Capabilities

### Tools

Core tools are always available. Plugin-integration tools register only when the matching plugin is active.

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

### Does Cowboy MCP work with WooCommerce?

Yes. When WooCommerce is active, Cowboy MCP registers 40 extra tools for products and variations, orders and refunds, customers, coupons, tax/shipping/gateway settings, and sales reports — using WooCommerce's own CRUD classes rather than raw SQL. Tools for ACF, Elementor, Wordfence, and cache plugins register automatically the same way.

### Do I need Node.js or WP-CLI?

No. Cowboy MCP serves the MCP endpoint natively from WordPress with zero external dependencies — no Node.js bridge, no Composer, no build step. It uses core WordPress APIs, so it works even on managed hosts that disable `shell_exec()` or lack WP-CLI. WP-CLI remains an optional power-user escape hatch when present.

### Is it safe to give an AI agent access to my site?

You're granting real control, so Cowboy MCP is built defensively. API keys are bcrypt-hashed and shown once, every request is rate-limited, safe mode requires explicit confirmation for destructive actions, and every tool call is recorded in an audit log. Sensitive options, dangerous SQL, and SSRF attempts are blocked by default.

### Which AI agents are supported?

Any MCP client that supports Streamable HTTP with a Bearer token — including Claude Code, Codex, and Cursor. The same endpoint works across clients: point the agent at your site's URL and supply the API key. More agents add MCP support regularly.

## Security

You are granting an AI agent significant control over your site. Cowboy MCP is built to keep that safe:

- **Authentication** — API keys are bcrypt-hashed (`wp_hash_password()`) and never stored in plain text. Keys are shown once at generation and can be revoked individually.
- **Rate limiting** — per-key, per-minute window (default 120/min).
- **Safe mode** *(on by default)* — destructive tools (delete posts, drop tables, write-mode WP-CLI, etc.) require an explicit `confirm: true`.
- **Dry run** — non-read-only tools accept a `dry_run` parameter to preview changes.
- **Audit log** — every tool call, error, and auth event is recorded in a database table with automatic 30-day pruning. Sensitive fields are redacted.
- **Guardrails** — option blocklist for sensitive settings, SQL blocklist for dangerous DDL, WP-CLI command blocklist, SSRF protection on outbound requests, `wp-content` path confinement for file ops, and self-delete protection.

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

## Updates

Cowboy MCP self-updates from GitHub Releases. It fetches a small JSON manifest from `COWBOY_MCP_UPDATE_URL` (default `https://cowboymcp.com/updates/cowboy-mcp.json`, overridable in `wp-config.php`), compares versions, and serves the release zip — all through WordPress's native update system. Auto-updates are owner-opt-in and fail closed: any manifest/network error simply means "no update," never a fatal.

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
  class-mcp-tools.php       Tool registry, dispatch, dry-run/safe-mode gating
  class-mcp-resources.php   Read-only resources + templates
  class-mcp-prompts.php     Workflow prompts
  class-mcp-updater.php     Self-hosted updater
  class-mcp-audit-log.php   DB-backed audit log
  class-mcp-security.php    Shared guardrails (blocklists, redaction, power mode)
  tools/                    Domain tool files (core, woocommerce, acf, …)
admin/
  class-mcp-admin.php       Settings page
```

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html). Cowboy MCP is free software.
