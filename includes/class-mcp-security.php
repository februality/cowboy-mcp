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
        // Block comments /* ... */ including /*! versioned */ comments.
        $sql = preg_replace( '#/\*.*?\*/#s', ' ', $sql );
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
            || stripos( $normalized, 'cowboy_mcp_settings' ) !== false;
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
}
