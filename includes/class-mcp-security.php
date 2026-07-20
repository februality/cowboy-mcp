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
