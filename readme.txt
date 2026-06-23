=== Cowboy MCP ===
Contributors: februality
Tags: mcp, ai, claude, api, automation
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Expose your WordPress site as a Model Context Protocol (MCP) server so AI coding agents can manage it.

== Description ==

Cowboy MCP turns your site into a full-featured [Model Context Protocol](https://modelcontextprotocol.io/) server. AI coding agents like Claude Code, Codex, and Opencode can connect over HTTP and manage posts, pages, plugins, themes, users, media, menus, WooCommerce stores, and much more — all through a single authenticated endpoint.

**Key features:**

* **Single REST endpoint** — JSON-RPC 2.0 over Streamable HTTP at `/wp-json/cowboy-mcp/v1/endpoint`
* **121 tools** — Full CRUD for posts, taxonomies, options, users, comments, media, menus, database queries, WP-CLI, and more
* **Plugin integrations** — Conditional tools for WooCommerce, ACF, Yoast/Rank Math, Elementor, Wordfence, WPForms/Gravity Forms/CF7, and cache plugins (WP Rocket, LiteSpeed, W3TC)
* **16 read-only resources** — Site info, recent posts, plugin/theme lists, WooCommerce summaries, and more
* **8 workflow prompts** — Site audit, content migration, SEO optimization, security hardening, and more
* **Secure by default** — API key authentication (bcrypt-hashed, shown once), per-key rate limiting, safe mode for destructive operations, comprehensive audit logging
* **Zero external dependencies** — No Composer, no npm, no CDN. Fully self-contained.

**How it works:**

1. Activate the plugin and generate an API key from Settings > Cowboy MCP.
2. Connect your AI agent using the provided setup command.
3. The agent can now read, create, update, and delete content on your site through the MCP protocol.

**Safe mode** (enabled by default) requires explicit confirmation for destructive operations like deleting posts, dropping database tables, or running write-mode WP-CLI commands. Every tool call is logged in the built-in audit log with automatic 30-day pruning.

== Installation ==

1. Upload the `cowboy-mcp` folder to the `/wp-content/plugins/` directory, or install directly through the WordPress plugin screen.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to **Settings > Cowboy MCP** and click **Generate API Key**.
4. Copy the API key (it is only shown once) and use it to connect your AI agent.

**Connecting Claude Code:**

    claude mcp add --transport http your-site /wp-json/cowboy-mcp/v1/endpoint --header "Authorization: Bearer YOUR_API_KEY"

**Connecting Codex:**

Set an environment variable:

    export COWBOY_MCP_API_KEY="YOUR_API_KEY"

Add to `~/.codex/config.toml`:

    [mcp_servers.your-site]
    url = "https://yoursite.com/wp-json/cowboy-mcp/v1/endpoint"
    bearer_token_env_var = "COWBOY_MCP_API_KEY"

== Frequently Asked Questions ==

= What is MCP? =

The [Model Context Protocol](https://modelcontextprotocol.io/) is an open standard that allows AI agents to interact with external tools and data sources through a unified interface. This plugin implements an MCP server that exposes WordPress management capabilities.

= Which AI agents are supported? =

Any MCP-compatible agent that supports Streamable HTTP transport. This includes Claude Code, Codex, and Opencode. More agents are adding MCP support regularly.

= Is this safe to use on a production site? =

The plugin is designed with security in mind: API keys are bcrypt-hashed and never stored in plain text, all requests are rate-limited, and safe mode (enabled by default) requires explicit confirmation for destructive operations. Every tool call is logged in the audit log. That said, you are granting an AI agent significant control over your site — use it responsibly and review the audit log regularly.

= What happens if I lose my API key? =

API keys are shown only once when generated. If you lose a key, revoke it from Settings > Cowboy MCP and generate a new one.

= Does this plugin send data to external services? =

No. The plugin is completely self-contained and makes no outbound connections. AI agents connect *to* your site — the plugin never phones home.

= Can I restrict which tools are available? =

The `cowboy_mcp_tool_allowed` filter lets you block specific tools per-request. You can also use the `cowboy_mcp_tools` filter to modify the tool registry.

== Changelog ==

= Unreleased =
* Updated for WordPress.org plugin directory compliance.

= 1.4.0 =
* New: Connect via the Claude Desktop / web app using a one-click OAuth sign-in (custom connectors) — no terminal required. Off by default; enable under Settings → Cowboy MCP → Settings → Desktop Connector. The existing terminal/API-key method is unchanged.

= 1.3.0 =
* Feature: self-hosted one-click updates. The plugin checks cowboymcp.com for new versions and offers updates through the normal WordPress Plugins screen, including the per-plugin auto-update toggle (owner opt-in). Updates are delivered from GitHub release assets over HTTPS. Fails closed — any check error simply shows no update.

= 1.2.0 =
* Feature: optional **Power mode** (admin opt-in, off by default) — lifts curated safety restrictions for trusted power users: `wp_cli` `eval`/`shell` and other blocked commands, dangerous SQL (DDL), writing files outside `wp-content/`, and SSRF protection on outbound requests. The plugin's own API keys and settings, credential redaction, and self-delete protection remain enforced; `safe_mode` confirmation gating is unaffected.

= 1.1.0 =
* Security: lock down `wp_db_query` — apply the write blocklist (incl. INTO OUTFILE/DUMPFILE) to the read path, reject comment-obfuscated and credential-targeting queries, and redact secret columns
* Security: block reads of sensitive options via resource templates, completions, and WooCommerce settings; block writes to payment-gateway/secret/role option groups
* Security: harden SSRF protection — resolve all A/AAAA records, normalize IPv4-mapped IPv6, deny loopback/link-local/ULA, and use the safe HTTP transport so redirects are re-validated
* Security: API-key validation no longer uses the secret-derived prefix as a fast path (timing side-channel); add a per-IP request throttle applied to all requests
* Security: block `--require`/loader global flags and whitespace-obfuscated commands in `wp_cli`; normalize the SQL write blocklist against inline comments
* Security: refuse executable file writes into the uploads directory; harden wp-content path confinement (null bytes, symlinked parents, sibling-prefix)
* Security: prevent deleting the last administrator and validate `reassign_to` in `wp_delete_user`
* Security: expand audit-log redaction, scrub secrets from error-log/transient output, relativize hook file paths, and add Origin validation
* Hardening: apply the tool allowlist before dry-run/safe-mode, and guard against nested batch execution

= 1.0.2 =
* Prefix all admin identifiers with `cowboy_mcp_` to avoid namespace collisions
* Change REST namespace from `mcp/v1` to `cowboy-mcp/v1`
* Sanitize `$_SERVER['REMOTE_ADDR']` with `sanitize_text_field()`
* Add capability checks to plugin activation/deactivation and theme switching

= 1.0.1 =
* Rename plugin integration files to avoid false-positive library detection
* Update author metadata

= 1.0.0 =
* Initial release
