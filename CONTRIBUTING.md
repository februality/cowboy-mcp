# Contributing to Cowboy MCP

Thanks for your interest in improving Cowboy MCP — a WordPress plugin that turns
any WordPress site into a Model Context Protocol (MCP) server for AI coding agents.

## Ways to contribute

- **Report bugs / request features** — open a [GitHub issue](https://github.com/februality/cowboy-mcp/issues) with steps to reproduce, your WordPress and PHP versions, and the relevant audit-log entry if applicable.
- **Submit a pull request** — fork the repo, create a feature branch, and open a PR against `main`.
- **Improve docs** — fixes to the README, FAQ, or examples are very welcome.

## Development notes

- **No build step, no dependencies.** The plugin is plain PHP using native WordPress
  APIs — no Composer, no npm. Classes are loaded via manual `require_once` in
  `cowboy-mcp.php`; tools live in domain files under `includes/tools/`.
- **PHP 8.0+** and WordPress 6.2+ are required. Follow WordPress PHP conventions
  (`WP_Error` returns, `sanitize_*`/`esc_*`, static classes with `::init()`).
- **Lint before submitting:** `find . -name '*.php' -exec php -l {} +`.
- **Security first.** This plugin grants AI agents control over a site. New tools
  must respect safe mode, the audit log, and the existing guardrails (option/SQL/
  command blocklists, SSRF protection, path confinement). See the README's
  Security section and `includes/class-mcp-security.php`.
- **Test manually** against a running WordPress instance — there is no automated
  test suite. Verify your tool over the live MCP endpoint with a real client.

## License

By contributing, you agree that your contributions are licensed under
[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html).
