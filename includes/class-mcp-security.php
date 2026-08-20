<?php
/**
 * Cowboy MCP – Security helpers
 *
 * Single source of truth for the sensitive-data denylist, SSRF URL validation,
 * secret scrubbing and SQL normalization shared across tools, resources and
 * completions. Centralizing these here keeps the various guardrails consistent
 * (previously each tool re-implemented its own, with gaps).
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Security {

    /** Exact option names that may never be written via MCP. */
    private const PROTECTED_OPTIONS = [
        'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role',
        'active_plugins', 'template', 'stylesheet', 'cron',
        'cowboy_mcp_api_keys', 'cowboy_mcp_settings',
        'upload_path', 'upload_url_path',
    ];

    /** Dangerous SQL operations, keyed by detection pattern. Applied to the wp_cli `db query`/`db search` escape hatch. */
    private const SQL_BLOCKED = [
        '/\bDROP\s+DATABASE\b/i'           => 'DROP DATABASE',
        '/\bDROP\s+TABLE\b/i'              => 'DROP TABLE',
        '/\bTRUNCATE\b/i'                  => 'TRUNCATE',
        '/\bCREATE\s+TRIGGER\b/i'          => 'CREATE TRIGGER',
        '/\bCREATE\s+PROCEDURE\b/i'        => 'CREATE PROCEDURE',
        '/\bCREATE\s+FUNCTION\b/i'         => 'CREATE FUNCTION',
        '/\bLOAD\s+DATA\b/i'               => 'LOAD DATA',
        '/\bALTER\s+TABLE\b/i'             => 'ALTER TABLE',
        '/\bRENAME\s+TABLE\b/i'            => 'RENAME TABLE',
        '/\bGRANT\b/i'                     => 'GRANT',
        '/\bREVOKE\b/i'                    => 'REVOKE',
        '/\bINTO\s+(OUTFILE|DUMPFILE)\b/i' => 'INTO OUTFILE/DUMPFILE',
    ];

    /**
     * Meta keys that are safe to write via MCP even though they begin with "_".
     * Everything else underscore-prefixed is treated as internal/protected.
     */
    private const WRITABLE_UNDERSCORE_META = [ '_thumbnail_id' ];

    /* ── Option denylist ───────────────────────────────────── */

    /**
     * Whether an option is protected from writes (wp_update_option, wp_woo_update_setting).
     */
    public static function is_protected_option( string $name ): bool {
        $name = strtolower( trim( $name ) );
        if ( in_array( $name, self::PROTECTED_OPTIONS, true ) ) {
            return true;
        }
        // Plugin internals, role/capability maps (matches {prefix}user_roles on any
        // table prefix), mail relay, secrets, and WooCommerce payment-gateway groups.
        return (bool) preg_match(
            '/^cowboy_mcp_|user_roles$|^mailserver_|secret|_api_key|^auth_|password|^woocommerce_.+_settings$/',
            $name
        );
    }

    /**
     * Whether an option is sensitive to read (resources, completion, wp_woo_get_setting).
     * Superset of is_protected_option, focused on confidentiality.
     */
    public static function is_sensitive_option( string $name ): bool {
        if ( self::is_protected_option( $name ) ) {
            return true;
        }
        $name = strtolower( trim( $name ) );
        return (bool) preg_match( '/secret|token|_api_key|api_key|_key$|password|nonce/', $name );
    }

    /**
     * Options that may NEVER be written via MCP, even when Power mode is on.
     * Narrower than is_protected_option(): credentials and plugin internals only.
     * Power mode lifts the rest of the write denylist (siteurl, home, active_plugins,
     * template, stylesheet, cron, {prefix}user_roles, woocommerce_*_settings, …).
     */
    public static function is_hard_protected_option( string $name ): bool {
        $name = strtolower( trim( $name ) );
        if ( $name === 'cowboy_mcp_api_keys' || $name === 'cowboy_mcp_settings' ) {
            return true;
        }
        return (bool) preg_match(
            '/^cowboy_mcp_|^mailserver_|secret|_api_key|^auth_|password|nonce|token/',
            $name
        );
    }

    /* ── Per-credential tool scoping ───────────────────────── */

    /**
     * The active credential's scope, or null when unscoped (full access).
     *
     * Reads the key context populated by Cowboy_MCP_Auth at validation time.
     * Contexts without a scope — wp-admin pages, WP-CLI, cron, the Activity-tab
     * undo path, and legacy key records — are unscoped, matching pre-scoping
     * behavior. A present scope with mode 'full' is also unscoped.
     *
     * @return array{mode:string,allowed_tools?:array}|null
     */
    public static function current_scope(): ?array {
        if ( ! class_exists( 'Cowboy_MCP_Auth' ) ) {
            return null;
        }
        $scope = Cowboy_MCP_Auth::$current_key_context['scope'] ?? null;
        if ( ! is_array( $scope ) || empty( $scope['mode'] ) || 'full' === $scope['mode'] ) {
            return null;
        }
        return $scope;
    }

    /**
     * Whether the active credential may call a tool. Unknown modes fail closed.
     * read_only relies on the tool's readOnlyHint annotation — with this gate
     * that annotation is a security boundary, not advisory metadata.
     */
    public static function tool_in_scope( string $name, array $annotations = [] ): bool {
        $scope = self::current_scope();
        if ( null === $scope ) {
            return true;
        }
        return match ( $scope['mode'] ) {
            'read_only' => ! empty( $annotations['readOnlyHint'] ),
            'custom'    => in_array( $name, (array) ( $scope['allowed_tools'] ?? [] ), true ),
            default     => false,
        };
    }

    /**
     * Filter tool definition arrays down to the active credential's scope.
     * Unscoped contexts get the input back untouched (no array copying).
     */
    public static function filter_tools_to_scope( array $tools ): array {
        if ( null === self::current_scope() ) {
            return $tools;
        }
        return array_values( array_filter(
            $tools,
            fn( $t ) => self::tool_in_scope( $t['name'] ?? '', $t['annotations'] ?? [] )
        ) );
    }

    /**
     * One-line scope statement for the MCP initialize instructions, or null
     * when unscoped. Intentionally NOT translated (MCP clients expect English).
     */
    public static function scope_description(): ?string {
        $scope = self::current_scope();
        if ( null === $scope ) {
            return null;
        }
        if ( 'read_only' === $scope['mode'] ) {
            return 'This credential is READ-ONLY: only read-only tools are available. Write tools are not listed and will be refused.';
        }
        $count = count( (array) ( $scope['allowed_tools'] ?? [] ) );
        return "This credential is scoped to {$count} of the site's tools; the tool catalog reflects only what it may call.";
    }

    /* ── Private storage path protection ──────────────────── */

    /**
     * Whether an absolute path is inside the plugin's private storage subtree
     * (uploads/cowboy-mcp — DB checkpoint dumps). Never lifted by Power mode:
     * these files are full-database plaintext and must stay unreachable by the
     * file tools, which would otherwise bypass DB-result secret redaction.
     */
    public static function is_protected_storage_path( string $abs_path ): bool {
        $uploads = wp_upload_dir();
        $base    = $uploads['basedir'] ?? '';
        if ( $base === '' ) {
            return false;
        }
        $storage = ( realpath( $base ) ?: $base ) . '/cowboy-mcp';
        $real    = realpath( $abs_path );
        $target  = $real !== false ? $real : $abs_path;
        return $target === $storage || str_starts_with( $target . '/', $storage . '/' );
    }

    /* ── Power mode ────────────────────────────────────────── */

    /**
     * Whether Power mode is enabled. Admin opt-in via cowboy_mcp_settings['power_mode'],
     * default false. Lifts a curated set of hard guardrails (see is_hard_protected_option()
     * and the gate sites in tools/). It can never be self-enabled via the API because
     * cowboy_mcp_settings is hard-protected from writes.
     */
    public static function power_mode_enabled(): bool {
        if ( ! class_exists( 'Cowboy_MCP_Tools' ) ) {
            return false;
        }
        return ! empty( Cowboy_MCP_Tools::get_settings()['power_mode'] );
    }

    /* ── Meta safety ───────────────────────────────────────── */

    /**
     * Whether a post/user/term meta key must be blocked from MCP writes.
     * Blocks internal underscore-prefixed keys (except a small allowlist) and any
     * key whose name reads as sensitive (secret/token/password/etc).
     */
    public static function is_blocked_meta_key( string $key ): bool {
        if ( str_starts_with( $key, '_' )
            && ! in_array( $key, self::WRITABLE_UNDERSCORE_META, true ) ) {
            return true;
        }
        return self::is_sensitive_option( $key );
    }

    /**
     * Redact a meta map ([key => value] or [key => [values]]) by key sensitivity.
     * Hard-masks credential columns, masks sensitive-named keys, and scrubs
     * Bearer/MCP tokens from any remaining string values.
     *
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public static function redact_meta( array $meta ): array {
        $hard = '/^(user_pass|user_activation_key|session_tokens)$/i';
        foreach ( $meta as $key => &$value ) {
            if ( preg_match( $hard, (string) $key ) || self::is_sensitive_option( (string) $key ) ) {
                $value = '[REDACTED]';
            } elseif ( is_string( $value ) ) {
                $value = self::scrub_secrets( $value );
            }
        }
        unset( $value );
        return $meta;
    }

    /* ── Role / user privilege ─────────────────────────────── */

    /**
     * Capabilities that make a role "privileged": settings control, code
     * execution, or the ability to hand either one to somebody else. Granting any
     * of these creates access that SURVIVES API-key revocation, so writes that
     * grant them are gated behind Power mode.
     *
     * The user-management caps are included because a role that can promote users
     * can escalate an account to administrator later — persistence at one remove.
     * Verified on WP 7.0.2 + WooCommerce 10.9.4 that this costs nothing in
     * practice: administrator is the only stock role holding them, and it is
     * already privileged via manage_options. (WooCommerce's shop_manager carries
     * none of them; older WC releases did, which is why this list once excluded
     * them.)
     */
    private const PRIVILEGED_CAPS = [
        'manage_options',
        'install_plugins', 'edit_plugins', 'activate_plugins', 'update_plugins',
        'install_themes', 'edit_themes', 'update_themes',
        'edit_files',
        'edit_users', 'promote_users', 'delete_users',
    ];

    /** Whether granting this role hands over settings or code-execution control. */
    public static function role_is_privileged( string $role ): bool {
        $role_obj = get_role( $role );
        if ( ! $role_obj ) {
            return false;
        }
        foreach ( self::PRIVILEGED_CAPS as $cap ) {
            if ( ! empty( $role_obj->capabilities[ $cap ] ) ) {
                return true;
            }
        }
        return false;
    }

    /** Whether an existing user currently holds settings or code-execution control. */
    public static function user_is_privileged( int|WP_User $user ): bool {
        $user_obj = $user instanceof WP_User ? $user : get_userdata( (int) $user );
        if ( ! $user_obj || ! $user_obj->exists() ) {
            return false;
        }
        foreach ( self::PRIVILEGED_CAPS as $cap ) {
            if ( user_can( $user_obj, $cap ) ) {
                return true;
            }
        }
        return false;
    }

    /* ── SQL safety ────────────────────────────────────────── */

    /**
     * Normalize SQL for detection only (never for execution): strip comments and
     * collapse whitespace so blocklist regexes can't be evaded with `DROP/**​/TABLE`.
     */
    public static function normalize_sql( string $sql ): string {
        // MySQL EXECUTES the body of `/*! ... */` and version-gated `/*!NNNNN ... */`
        // comments, so their contents must be unwrapped into the scanned text rather
        // than stripped — deleting them would hide live SQL from the blocklist
        // (`/*!50000 DROP */ TABLE t` reaches the server as `DROP TABLE t`). The
        // version number is ignored on purpose: we do not know the server version, and
        // scanning a body the server might skip only over-blocks, which is the safe
        // direction. Must run BEFORE the plain-comment strip below, which would
        // otherwise swallow these too.
        $sql = preg_replace( '#/\*!\d*\s*(.*?)\*/#s', ' $1 ', $sql );
        // Plain block comments /* ... */ are true comments — strip them.
        $sql = preg_replace( '#/\*.*?\*/#s', ' ', (string) $sql );
        // Line comments -- ... and # ... to end of line.
        $sql = preg_replace( '/(--|#)[^\r\n]*/', ' ', (string) $sql );
        // Collapse whitespace.
        $sql = preg_replace( '/\s+/', ' ', (string) $sql );
        return trim( (string) $sql );
    }

    /**
     * Return the label of the first blocked operation found, or null if clean.
     */
    public static function sql_blocked_reason( string $normalized ): ?string {
        foreach ( self::SQL_BLOCKED as $pattern => $label ) {
            if ( preg_match( $pattern, $normalized ) ) {
                return $label;
            }
        }
        return null;
    }

    /**
     * Whether a (normalized) query references credential/secret data sources.
     */
    public static function sql_touches_secret( string $normalized ): bool {
        if ( preg_match( '/\b(user_pass|user_activation_key|session_tokens)\b/i', $normalized ) ) {
            return true;
        }
        return stripos( $normalized, 'cowboy_mcp_api_keys' ) !== false
            || stripos( $normalized, 'cowboy_mcp_settings' ) !== false
            || stripos( $normalized, 'cowboy_mcp_oauth_clients' ) !== false
            || stripos( $normalized, 'cowboy_mcp_oauth_tokens' ) !== false
            || stripos( $normalized, 'cowboy_mcp_oauth_refresh' ) !== false;
    }

    /* ── WP-CLI command gating ─────────────────────────────── */

    /**
     * Global WP-CLI flags that load/execute code or change execution context.
     * Matched per-token (post-tokenization) against `--flag` or `--flag=value`.
     */
    private const CLI_DANGEROUS_FLAGS = '/^--(require|exec|ssh|http|user|path|config|debug)(=|$)/i';

    /**
     * Subcommand paths always blocked for the wp_cli tool, regardless of safe
     * mode. Matched against the tokenized *positional* sequence (see
     * cli_tokens()), not the raw string. Power mode can lift this list — the
     * cowboy_mcp_ option-write guard below is the one that never lifts.
     */
    private const CLI_BLOCKED_PATHS = [
        'db drop', 'db reset', 'db import', 'db export',
        'site empty', 'core download',
        'eval', 'eval-file',
        'config set', 'config create',
        'shell', 'package install',
    ];

    /**
     * wp_cli safe-mode allowlist: subcommand paths that may run without
     * confirm:true when safe mode is on. Matched the same way as CLI_BLOCKED_PATHS.
     */
    private const CLI_SAFE_PATHS = [
        'cache', 'cron', 'db size', 'export',
        'help', 'media', 'menu', 'option get', 'option list',
        'plugin list', 'plugin status', 'post list', 'post get',
        'rewrite', 'role list', 'sidebar', 'taxonomy', 'term',
        'theme list', 'theme status', 'transient', 'user list', 'user get',
        'widget',
    ];

    /**
     * Tokenize a WP-CLI command the way a POSIX shell would before it reaches
     * shell_exec() (i.e. /bin/sh -c). This is required because every wp_cli
     * gate below used to match with str_starts_with()/preg_match() against the
     * raw command string — but the shell strips quotes and joins
     * adjacent-quoted/escaped fragments into single argv words BEFORE WP-CLI
     * ever sees them. `option update cowboy_mcp_settings x` and
     * `option update 'cowboy_mcp_settings' x` are the exact same command to
     * the shell (and to WP-CLI), but only the first matches a raw-string
     * prefix check — the quoted form silently bypassed every guard. Tokenizing
     * first and matching on the resulting words closes that gap for good,
     * including flag-insertion tricks (`option update --format=json
     * cowboy_mcp_settings x`), since flags are filtered out of the positional
     * sequence entirely.
     *
     * Handles single quotes (literal, no escapes inside), double quotes
     * (backslash escapes \, ", $, ` inside), backslash escapes outside quotes,
     * and adjacent-token concatenation (`ev'al'`, `"ev"al` both tokenize to
     * "eval"). It does not perform variable/command substitution — this is a
     * detection aid, never used to build the string that gets executed.
     *
     * @return array{tokens:string[],positionals:string[],flags:string[]}
     */
    public static function cli_tokens( string $command ): array {
        $tokens       = [];
        $bare_leading = []; // parallel to $tokens: was token[$i]'s first char typed bare (unquoted/unescaped)?
        $current      = '';
        $in_token     = false;
        // Nullable: unset until the current token's first character is appended,
        // then locked true (bare) or false (from a quote/escape) for that token.
        $leading_literal = null;
        $len             = strlen( $command );
        $i               = 0;

        while ( $i < $len ) {
            $c = $command[ $i ];

            if ( $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" ) {
                if ( $in_token ) {
                    $tokens[]        = $current;
                    $bare_leading[]  = $leading_literal === true;
                    $current         = '';
                    $in_token        = false;
                    $leading_literal = null;
                }
                $i++;
                continue;
            }

            if ( $c === "'" ) {
                // Single quotes: everything up to the next ' is literal.
                $in_token = true;
                $i++;
                while ( $i < $len && $command[ $i ] !== "'" ) {
                    if ( $current === '' && null === $leading_literal ) {
                        $leading_literal = false; // first char came from inside quotes
                    }
                    $current .= $command[ $i ];
                    $i++;
                }
                $i++; // consume the closing quote (or run off the end if unterminated)
                continue;
            }

            if ( $c === '"' ) {
                // Double quotes: backslash escapes \, ", $, ` — everything else literal.
                $in_token = true;
                $i++;
                while ( $i < $len && $command[ $i ] !== '"' ) {
                    if ( $current === '' && null === $leading_literal ) {
                        $leading_literal = false; // first char came from inside quotes
                    }
                    if ( $command[ $i ] === '\\' && $i + 1 < $len
                        && in_array( $command[ $i + 1 ], [ '"', '\\', '$', '`' ], true ) ) {
                        $current .= $command[ $i + 1 ];
                        $i       += 2;
                    } else {
                        $current .= $command[ $i ];
                        $i++;
                    }
                }
                $i++; // consume the closing quote
                continue;
            }

            if ( $c === '\\' ) {
                // Outside quotes: backslash escapes the very next character. Treated the
                // same as a quote for provenance purposes — explicit escaping is the same
                // "make this literal data" signal as wrapping it in quotes.
                $in_token = true;
                if ( $current === '' && null === $leading_literal ) {
                    $leading_literal = false;
                }
                if ( $i + 1 < $len ) {
                    $current .= $command[ $i + 1 ];
                    $i       += 2;
                } else {
                    $i++;
                }
                continue;
            }

            if ( $current === '' && null === $leading_literal ) {
                $leading_literal = true; // first char was typed bare, outside any quote/escape
            }
            $in_token = true;
            $current .= $c;
            $i++;
        }
        if ( $in_token ) {
            $tokens[]       = $current;
            $bare_leading[] = $leading_literal === true;
        }

        // A token is only flag-eligible when its FIRST character was typed bare —
        // i.e. genuinely outside any quoting/escaping, exactly as a real `-`/`--`
        // CLI flag is typed. This is deliberately narrower than "the whole token
        // is unquoted": `--'require'=x` still counts (the leading -- is bare, only
        // the flag NAME is quoted) so quoted-flag obfuscation still gets caught.
        // But a whole-token-quoted SQL payload like `"-1 or ...=cowboy_mcp_api_keys"`
        // does NOT count — its leading '-' came from inside the quotes, so treating
        // it as a flag (and dropping it from $positionals) would make the wp_cli
        // guards see LESS of the command than WP-CLI itself executes: WP-CLI does
        // not flag-classify a quoted positional argument just because the string it
        // resolves to happens to start with '-'. Without this distinction,
        // `db query "-1 or option_name='cowboy_mcp_api_keys'"` would have its entire
        // SQL payload silently dropped from $positionals before the SQL guard ever
        // saw it — a real regression a blanket `str_starts_with($token,'-')` rule
        // introduced.
        $positionals = [];
        $flags       = [];
        foreach ( $tokens as $idx => $token ) {
            if ( str_starts_with( $token, '-' ) && ( $bare_leading[ $idx ] ?? false ) ) {
                $flags[] = $token;
            } else {
                $positionals[] = $token;
            }
        }

        return [ 'tokens' => $tokens, 'positionals' => $positionals, 'flags' => $flags ];
    }

    /**
     * Whether a leading run of positional tokens matches a space-separated
     * subcommand path, case-insensitively — e.g. path 'db drop' matches
     * positionals ['db','drop','wp_options',...]. Word-boundary match against
     * whole tokens, not a substring/prefix match, so 'db' alone never matches
     * path 'db drop' and 'eval' never matches a positional 'eval-file'.
     */
    public static function cli_positionals_start_with( array $positionals, string $path ): bool {
        $parts = preg_split( '/\s+/', trim( $path ) );
        if ( count( $positionals ) < count( $parts ) ) {
            return false;
        }
        foreach ( $parts as $idx => $part ) {
            if ( strtolower( (string) $positionals[ $idx ] ) !== strtolower( $part ) ) {
                return false;
            }
        }
        return true;
    }

    /** Whether any raw token contains $needle, case-insensitively (used for the "option patch ... cowboy_mcp_*" any-argument form). */
    private static function cli_tokens_contain( array $tokens, string $needle ): bool {
        foreach ( $tokens as $token ) {
            if ( str_contains( strtolower( $token ), strtolower( $needle ) ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Evaluate every wp_cli safety gate against a command and report the
     * first one that fires. Pure function (no WP calls) so it can be unit
     * tested directly: tokenizes first (see cli_tokens() for why raw-string
     * matching is bypassable) and matches every check against the resulting
     * positional token sequence instead of the raw string.
     *
     * Check order mirrors the pre-fix handler, with two additions at the front
     * that reject leading tokens WP-CLI itself would never resolve as a
     * command (a leading @alias, or a leading `-`/`--`/anything starting with
     * `-`) — both would otherwise just shift every positional-anchored check
     * below by one slot — then dangerous global flags, the cowboy_mcp_
     * option-write guard (never lifted by Power mode — the wp_cli-escape-hatch
     * equivalent of the wp_update_option write-denylist), the always-blocked
     * subcommand list, the db query|search SQL blocklist + secret-table check
     * (which fails closed on malformed/ambiguous argument shapes — see
     * inline comments — rather than risk under-scanning the payload), and
     * finally — only when $safe_mode is on and $confirm is false — the
     * known-safe-prefix allowlist.
     *
     * @return array{blocked:bool,code:?string,message:?string}
     */
    public static function wp_cli_gate( string $command, bool $power_mode, bool $safe_mode, bool $confirm ): array {
        $clear = [ 'blocked' => false, 'code' => null, 'message' => null ];

        $t           = self::cli_tokens( $command );
        $positionals = $t['positionals'];

        // 0. WP-CLI's alias resolver CONSUMES a leading @alias token before command
        //    resolution (`wp @prod option get x` runs `option get x` against whatever
        //    @prod points to) — so a leading @alias in positionals[0] would silently
        //    shift every other positional-anchored check below by one slot, e.g.
        //    `@prod option update cowboy_mcp_settings x` would read as positionals
        //    [@prod, option, update, ...] and never match the option-guard's
        //    positionals[0]==='option' anchor. This tool always targets the local
        //    install, so an alias is never legitimate here — reject outright,
        //    always-blocked (never lifted by Power mode: it guards every other
        //    check's anchor, including the bootstrap-invariant option guard).
        //    Only the FIRST positional is checked — an @ inside a later argument
        //    (an email address, etc.) is unaffected.
        if ( isset( $positionals[0] ) && str_starts_with( $positionals[0], '@' ) ) {
            return [
                'blocked' => true,
                'code'    => 'blocked',
                'message' => 'WP-CLI aliases (@alias) are not allowed; this tool always targets the local install.',
            ];
        }

        // 0b. Same category of problem, different token: WP-CLI cannot resolve a
        //     leading `-`, `--`, or anything else starting with `-` as a command name
        //     at all (verified live: `wp -- option get siteurl` => "'--' is not a
        //     registered wp command"). Both this and the @alias check above exist
        //     because a leading token WP-CLI would never resolve as a command only
        //     ever shifts our positional anchors — it can't run anything legitimate,
        //     so rejecting it loses nothing. This one matters because the
        //     quoting-provenance fix (see cli_tokens()) correctly stopped
        //     flag-classifying a QUOTED leading `'--'`/`'-'` — which is right for SQL
        //     payloads like `db query "-1 or ..."`, but means a quoted `'--'` used AS
        //     positionals[0] now lands as an ordinary positional instead of being
        //     dropped, silently shifting the option guard / blocked-paths anchors by
        //     one slot (e.g. `'--' option update cowboy_mcp_settings x`). Unconditional
        //     — never lifted by Power mode — for the same reason as the @alias check.
        if ( isset( $positionals[0] ) && str_starts_with( $positionals[0], '-' ) ) {
            return [
                'blocked' => true,
                'code'    => 'blocked',
                'message' => "'{$positionals[0]}' is not a valid WP-CLI command and is blocked.",
            ];
        }

        // 1. Global flags that load/execute code or change execution context.
        //    `--require=<file>` alone is arbitrary PHP execution that would bypass
        //    the eval/shell blocklist; `--path` is supplied by the caller separately.
        if ( ! $power_mode ) {
            foreach ( $t['flags'] as $flag ) {
                if ( preg_match( self::CLI_DANGEROUS_FLAGS, $flag ) ) {
                    return [
                        'blocked' => true,
                        'code'    => 'blocked',
                        'message' => 'Global WP-CLI flags (--require, --exec, --ssh, --http, --user, --path, --config) are not allowed.',
                    ];
                }
            }
        }

        // 2. WP-CLI writes to the plugin's own cowboy_mcp_ options are never allowed,
        //    Power mode included — preserves the cowboy_mcp_settings bootstrap
        //    invariant (an agent can never widen its own scope via the wp_cli escape hatch).
        if ( isset( $positionals[0] ) && strtolower( $positionals[0] ) === 'option' ) {
            $verb           = isset( $positionals[1] ) ? strtolower( $positionals[1] ) : '';
            $name           = isset( $positionals[2] ) ? strtolower( $positionals[2] ) : '';
            $is_write_verb  = in_array( $verb, [ 'update', 'delete', 'add' ], true ) && str_starts_with( $name, 'cowboy_mcp_' );
            $is_patch_touch = $verb === 'patch' && self::cli_tokens_contain( $t['tokens'], 'cowboy_mcp_' );
            if ( $is_write_verb || $is_patch_touch ) {
                return [
                    'blocked' => true,
                    'code'    => 'blocked',
                    'message' => 'WP-CLI writes to the plugin\'s own cowboy_mcp_ options are blocked for safety and cannot be lifted by Power mode.',
                ];
            }
        }

        // 3. Always-blocked subcommand paths.
        if ( ! $power_mode ) {
            foreach ( self::CLI_BLOCKED_PATHS as $path ) {
                if ( self::cli_positionals_start_with( $positionals, $path ) ) {
                    return [
                        'blocked' => true,
                        'code'    => 'blocked',
                        'message' => "Command '{$path}' is blocked for safety. An administrator can enable Power mode to allow this.",
                    ];
                }
            }
        }

        // 4. `db query`/`db search` run arbitrary SQL via shell_exec with no guardrails
        //    of their own. Apply the same blocklist/secret-table checks used elsewhere
        //    so the wp_cli escape hatch can't run blocked or credential-touching SQL.
        //    Both detection (db/query/search) AND extraction of the SQL payload use the
        //    tokenized positionals: detection is immune to quoting tricks on the verb
        //    itself (db 'query' ...), and extraction — array_slice($positionals, 2) —
        //    is immune to a flag landing between the verb and the SQL
        //    (`db query --format=csv "SELECT * FROM cowboy_mcp_api_keys"`), which used
        //    to leak a leading "--" into the payload; normalize_sql() treats "--" as a
        //    SQL line-comment marker and strips everything after it, so the guard saw
        //    an EMPTY string and let the query through. Flags never enter $positionals
        //    in the first place, so this can't happen regardless of where the flag
        //    lands. Quotes are already stripped (and internal spaces/quotes/parens
        //    preserved verbatim) by cli_tokens() for each positional, so no further
        //    trimming is needed here.
        if ( isset( $positionals[0], $positionals[1] )
            && strtolower( $positionals[0] ) === 'db'
            && in_array( strtolower( $positionals[1] ), [ 'query', 'search' ], true ) ) {
            // WP-CLI's `db query`/`db search` synopsis takes exactly ONE positional
            // argument after the verb (the query/pattern). Blindly implode()-ing
            // array_slice($positionals, 2) assumed that was always true; it isn't once
            // a quoted, no-longer-flag-classified `'--'` can sit between the verb and
            // the real SQL — that got folded INTO the joined string, and
            // normalize_sql()'s comment-stripper then ate everything after it,
            // reintroducing the exact "guard scans an empty string" failure this whole
            // branch exists to prevent. Fail closed on anything that isn't the single
            // expected argument rather than guess which part is the real payload.
            // Unconditional — never lifted by Power mode — same footing as the
            // never-lifted secret-table check below: this protects that check's own
            // soundness, not a convenience restriction Power mode is meant to relax.
            $sql_positionals = array_slice( $positionals, 2 );
            if ( count( $sql_positionals ) > 1 ) {
                return [
                    'blocked' => true,
                    'code'    => 'blocked',
                    'message' => 'Malformed db query/search command: unexpected extra arguments.',
                ];
            }
            $raw_sql = $sql_positionals[0] ?? '';
            $sql     = self::normalize_sql( $raw_sql );
            // Defense in depth: never let "nothing left to scan" silently mean
            // "allowed" when a real payload was actually passed (e.g. a lone `--`
            // comment marker that normalize_sql() strips to nothing) — fail closed
            // instead of letting the checks below trivially pass on an empty string.
            // A genuinely argument-less `db query` (no payload at all, e.g. relying on
            // stdin, which this tool never provides) is unaffected: $raw_sql is itself
            // empty, so there was nothing to hide in the first place.
            if ( '' === trim( $sql ) && '' !== trim( $raw_sql ) ) {
                return [
                    'blocked' => true,
                    'code'    => 'blocked',
                    'message' => 'Malformed db query/search command: could not safely evaluate the SQL payload.',
                ];
            }
            $why = self::sql_blocked_reason( $sql );
            if ( $why && ! $power_mode ) {
                return [ 'blocked' => true, 'code' => 'blocked', 'message' => "SQL operation blocked: {$why}." ];
            }
            // Credential/secret access is never lifted, even in Power mode.
            if ( self::sql_touches_secret( $sql ) ) {
                return [ 'blocked' => true, 'code' => 'blocked', 'message' => 'Query references credential/secret data and is blocked.' ];
            }
        }

        // 5. Safe mode: only allow known-safe subcommand paths unless confirm: true.
        if ( $safe_mode && ! $confirm ) {
            $is_safe = false;
            foreach ( self::CLI_SAFE_PATHS as $path ) {
                if ( self::cli_positionals_start_with( $positionals, $path ) ) {
                    $is_safe = true;
                    break;
                }
            }
            if ( ! $is_safe ) {
                return [
                    'blocked' => false,
                    'code'    => 'confirmation_required',
                    'message' => "Safe mode is ON. WP-CLI command '{$command}' is not in the safe allowlist. Resend with confirm: true to execute.",
                ];
            }
        }

        return $clear;
    }

    /* ── Secret scrubbing ──────────────────────────────────── */

    /**
     * Replace Bearer tokens and raw MCP keys in free text (logs, error-log lines).
     */
    public static function scrub_secrets( string $text ): string {
        $text = preg_replace( '/\bBearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [REDACTED]', $text );
        $text = preg_replace( '/\bmcp_[0-9a-f]{16,}\b/i', '[REDACTED]', (string) $text );
        return (string) $text;
    }

    /* ── SSRF URL validation ───────────────────────────────── */

    /**
     * Validate a URL for outbound fetches: http(s) only, and every resolved IP
     * (all A + AAAA records) must be public. Rejects private, reserved, loopback,
     * link-local (cloud metadata 169.254.0.0/16) and unique-local addresses.
     *
     * @return bool|WP_Error
     */
    public static function validate_url_ssrf( string $url ): bool|WP_Error {
        $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
            return new WP_Error( 'invalid_scheme', 'Only http and https URLs are allowed.' );
        }

        // Power mode lifts the private/reserved-IP rejection. Scheme validation (above)
        // still applies; the transport is also switched to the unguarded variant at the
        // call sites so WordPress does not re-block the request.
        if ( self::power_mode_enabled() ) {
            return true;
        }

        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! $host ) {
            return new WP_Error( 'invalid_url', 'Could not parse hostname from URL.' );
        }
        $host = trim( $host, '[]' ); // strip IPv6 literal brackets

        $ips = [];
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            $ips[] = $host;
        } else {
            if ( function_exists( 'dns_get_record' ) ) {
                $records = @dns_get_record( $host, DNS_A | DNS_AAAA );
                if ( is_array( $records ) ) {
                    foreach ( $records as $r ) {
                        if ( ! empty( $r['ip'] ) )   $ips[] = $r['ip'];
                        if ( ! empty( $r['ipv6'] ) ) $ips[] = $r['ipv6'];
                    }
                }
            }
            if ( empty( $ips ) ) {
                $resolved = gethostbyname( $host );
                if ( $resolved && $resolved !== $host ) {
                    $ips[] = $resolved;
                }
            }
        }

        if ( empty( $ips ) ) {
            return new WP_Error( 'ssrf_blocked', 'Could not resolve hostname. URL blocked for safety.' );
        }

        foreach ( $ips as $ip ) {
            if ( ! self::ip_is_public( $ip ) ) {
                return new WP_Error( 'ssrf_blocked', 'URL targets a private, reserved, or link-local address.' );
            }
        }
        return true;
    }

    /**
     * Whether an IP is safe to connect to (public, routable).
     */
    private static function ip_is_public( string $ip ): bool {
        // Normalize IPv4-mapped IPv6 (::ffff:a.b.c.d) to plain IPv4.
        if ( stripos( $ip, '::ffff:' ) === 0 ) {
            $mapped = substr( $ip, 7 );
            if ( filter_var( $mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
                $ip = $mapped;
            }
        }
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return false;
        }
        // RFC1918, loopback, link-local (169.254/16), ULA (fc00::/7), reserved, ::1, 0.0.0.0.
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
            return false;
        }
        // Belt-and-suspenders explicit denies across PHP versions.
        if ( in_array( $ip, [ '0.0.0.0', '::', '::1' ], true ) ) {
            return false;
        }
        if ( preg_match( '/^(127\.|169\.254\.|10\.|192\.168\.)/', $ip ) ) {
            return false;
        }
        return true;
    }

    /* ── Local-development host heuristics (ADVISORY ONLY) ── */

    /**
     * Whether a hostname looks like a local development site.
     *
     * INVARIANT — advisory only, for UI copy and setup hints. This must NEVER
     * gate security behavior (SSRF validation, auth, rate limits, scoping):
     * it derives from home_url(), which is influenceable via WP_HOME, the
     * home option, and domain-mapping plugins, so "local mode" must never
     * loosen anything. Deliberately does no DNS lookups — string and
     * IP-literal checks only, cheap enough for admin page render.
     */
    public static function host_looks_local( string $host ): bool {
        $host = strtolower( trim( (string) $host, '[]' ) );
        if ( '' === $host ) {
            return true;
        }
        if ( self::host_is_loopback_shaped( $host ) ) {
            return true;
        }
        if ( preg_match( '/\.(internal|example|invalid)$/', $host ) ) {
            return true;
        }
        // Private/reserved IP literals (e.g. 192.168.1.20). No DNS resolution.
        if ( filter_var( $host, FILTER_VALIDATE_IP ) && ! self::ip_is_public( $host ) ) {
            return true;
        }
        return false;
    }

    /**
     * Narrower than host_looks_local(): names that virtually always resolve to
     * THIS machine (loopback). Used to decide when it is reasonable to suggest
     * a plain-http connection snippet — a bare private IP like 192.168.1.20 is
     * excluded on purpose, because that can be another machine on the LAN and
     * a Bearer key must not be suggested over plaintext on a real network.
     * Same ADVISORY-ONLY invariant as host_looks_local().
     */
    public static function host_is_loopback_shaped( string $host ): bool {
        $host = strtolower( trim( (string) $host, '[]' ) );
        if ( in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true ) ) {
            return true;
        }
        return (bool) preg_match( '/\.(localhost|local|test)$/', $host );
    }
}
