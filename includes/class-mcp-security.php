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

    /** Cowboy's own settings and credential rows, which an option_name LIKE may not reach. */
    private const GUARDED_OPTIONS = [
        'cowboy_mcp_settings', 'cowboy_mcp_api_keys', 'cowboy_mcp_oauth_clients',
        'cowboy_mcp_oauth_tokens', 'cowboy_mcp_oauth_refresh', 'cowboy_mcp_oauth_blocked',
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
     * Whether an option/meta/transient name is plain printable ASCII once trimmed.
     *
     * The options table compares names with an accent-, width- and case-insensitive
     * collation (utf8mb4_unicode_520_ci, utf8mb4_0900_ai_ci), so `cowböy_mcp_settings`,
     * a fullwidth letter or a zero-width space selects and updates the real
     * `cowboy_mcp_settings` row while matching none of the denylist patterns. Every
     * name check below treats a non-plain name as protected (fail closed).
     */
    public static function is_plain_name( string $name ): bool {
        $name = trim( $name );
        // Longer than wp_options.option_name (191): wpdb turns strict mode off, so MySQL
        // cuts the name on save, and a PAD SPACE collation then matches `default_role`
        // + spaces + `x` against the real `default_role` row (overwritten in wp-env).
        return strlen( $name ) <= 191 && (bool) preg_match( '/^[\x20-\x7e]+$/', $name );
    }

    /**
     * Stored option names a write to $name binds to. The table collation, not PHP,
     * decides which rows `WHERE option_name = %s` matches, so write gates judge these
     * as well as the name the agent sent.
     *
     * @return string[]
     */
    public static function stored_option_names( string $name ): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- collation probe; must hit the table, never the options cache.
        $rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s", trim( $name ) ) );
        return array_map( 'strval', (array) $rows );
    }

    /**
     * Why a write to option $name must be refused: 'invalid' (not plain ASCII),
     * 'hard' (never writable), 'soft' (writable only in Power mode), or null.
     * Checks the given name and every stored name it binds to.
     */
    public static function option_write_block( string $name ): ?string {
        if ( ! self::is_plain_name( $name ) ) {
            return 'invalid';
        }
        $names = array_unique( array_merge( [ $name ], self::stored_option_names( $name ) ) );
        $block = null;
        foreach ( $names as $candidate ) {
            if ( self::is_hard_protected_option( $candidate ) ) {
                return 'hard';
            }
            if ( self::is_protected_option( $candidate ) ) {
                $block = 'soft';
            }
        }
        return $block;
    }

    /**
     * Whether an option is protected from writes (wp_update_option, wp_woo_update_setting).
     */
    public static function is_protected_option( string $name ): bool {
        if ( ! self::is_plain_name( $name ) ) {
            return true;
        }
        $name = strtolower( trim( $name ) );
        if ( in_array( $name, self::PROTECTED_OPTIONS, true ) ) {
            return true;
        }
        // Plugin internals, role/capability maps (matches {prefix}user_roles on any
        // table prefix), mail relay, secrets, and WooCommerce payment-gateway groups.
        // The plugin's transients live in the options table as _transient_cowboy_mcp_* /
        // _transient_timeout_cowboy_mcp_* (and _site_transient_… on multisite): the OAuth
        // registration window, auth codes, rate-limit buckets and the CIMD document cache.
        return (bool) preg_match(
            '/^cowboy_mcp_|^(_site)?_transient_(timeout_)?cowboy_mcp_|user_roles$|^mailserver_|secret|_api_key|^auth_|password|^woocommerce_.+_settings$/',
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
        return (bool) preg_match( self::SECRET_OPTION_NAMES, $name );
    }

    /**
     * Option names whose whole value is a secret: generic credential words, the
     * wp-config-style salts/keys stored as options, WordPress's recovery-mode keys,
     * and plugins that keep tokens under innocuous names (Jetpack, Freemius,
     * WooCommerce.com helper).
     */
    private const SECRET_OPTION_NAMES = '/secret|token|api_?key|_key$|_salt$|password|nonce|^recovery_keys$|_private_options$|^fs_accounts$|^woocommerce_helper_data$/';

    /**
     * Settings blobs known to carry credentials somewhere inside (SMTP passwords,
     * licence keys, CDN/Cloudflare tokens, backup-storage credentials). Readable
     * through the redacting paths (redact_option_value()); refused where the value
     * cannot be redacted (wp_cli output).
     */
    private const SECRET_BEARING_OPTIONS = '/^(wp_mail_smtp|edd_settings|give_settings|wp_rocket_settings|w3tc_config|wpforms_settings|post_smtp_options|swpsmtp_options|easy_wp_smtp)$|^updraft_|^litespeed\.conf\./';

    /** Whether an option's value carries secrets inside it (see SECRET_BEARING_OPTIONS). */
    public static function is_secret_bearing_option( string $name ): bool {
        return self::is_sensitive_option( $name ) || (bool) preg_match( self::SECRET_BEARING_OPTIONS, strtolower( trim( $name ) ) );
    }

    /** Key parts (letters only, lower-cased) that mark a nested setting as a secret. */
    private const SECRET_VALUE_KEY_PARTS = [ 'pass', 'pwd', 'pswd', 'secret', 'token', 'bearer', 'nonce', 'salt', 'licence', 'license', 'credential', 'private', 'signature', 'dsn', 'webhook', 'jwt', 'cookie', 'session' ];

    /**
     * Redact an option value for display: every nested entry whose key reads as a
     * credential is masked, and remaining strings lose Bearer tokens, MCP keys and
     * payment-provider secret keys. Objects (incl. unserialized plugin classes) are
     * flattened to arrays first. Display only — never write the result back.
     */
    public static function redact_option_value( mixed $value ): mixed {
        if ( is_object( $value ) ) {
            $value = json_decode( (string) wp_json_encode( $value ), true );
        }
        if ( is_array( $value ) ) {
            foreach ( $value as $key => &$item ) {
                // Booleans and empty strings say nothing secret (`use_password: false`) — keep them.
                if ( is_string( $key ) && self::value_key_is_secret( $key ) && ! is_bool( $item ) && null !== $item && '' !== $item ) {
                    $item = '[REDACTED]';
                } else {
                    $item = self::redact_option_value( $item );
                }
            }
            unset( $item );
            return $value;
        }
        if ( is_string( $value ) ) {
            // Settings stored as JSON text inside an option: redact the decoded tree.
            $trim = ltrim( $value );
            if ( '' !== $trim && ( '{' === $trim[0] || '[' === $trim[0] ) ) {
                $decoded = json_decode( $value, true );
                if ( is_array( $decoded ) ) {
                    return (string) wp_json_encode( self::redact_option_value( $decoded ) );
                }
            }
            $value = self::scrub_secrets( $value );
            return (string) preg_replace( '/\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{8,}|\bwhsec_[A-Za-z0-9]{8,}/', '[REDACTED]', $value );
        }
        return $value;
    }

    /** Whether a nested settings key names a credential (camelCase- and separator-safe). */
    private static function value_key_is_secret( string $key ): bool {
        $norm = (string) preg_replace( '/[^a-z]/', '', strtolower( $key ) );
        if ( '' === $norm ) {
            return false;
        }
        // apikey, authkey, licensekey, consumerkey, secretkey, cfkey, …
        if ( str_ends_with( $norm, 'key' ) || str_ends_with( $norm, 'keys' ) ) {
            return true;
        }
        // `auth` (not author/authority) and a standalone `pw` segment (smtp_pw, smtpPw).
        if ( preg_match( '/auth(?!or)/', $norm ) || preg_match( '/(^|[^a-z])pw($|[^a-z])|[a-z]Pw($|[^a-z])/i', $key ) ) {
            return true;
        }
        foreach ( self::SECRET_VALUE_KEY_PARTS as $part ) {
            if ( str_contains( $norm, $part ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Options that may NEVER be written via MCP, even when Power mode is on.
     * Narrower than is_protected_option(): credentials and plugin internals only.
     * Power mode lifts the rest of the write denylist (siteurl, home, active_plugins,
     * template, stylesheet, cron, {prefix}user_roles, woocommerce_*_settings, …).
     */
    public static function is_hard_protected_option( string $name ): bool {
        if ( ! self::is_plain_name( $name ) ) {
            return true;
        }
        $name = strtolower( trim( $name ) );
        if ( $name === 'cowboy_mcp_api_keys' || $name === 'cowboy_mcp_settings' ) {
            return true;
        }
        return (bool) preg_match(
            '/^cowboy_mcp_|^(_site)?_transient_(timeout_)?cowboy_mcp_|^mailserver_|secret|_api_key|^auth_|password|nonce|token/',
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
            'drafts'    => Cowboy_MCP_Drafts::tool_allowed( $name, $annotations ),
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
        if ( 'drafts' === $scope['mode'] ) {
            return 'This credential is DRAFTS-ONLY: it may read everything, create posts and pages only as draft or pending, edit unpublished content, and upload media. Edits to live (published, scheduled or private) posts are saved to a linked draft copy for the owner to review and publish; results say so in draft_copy. It cannot publish, delete, or change anything else on the site.';
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
        return self::path_within_folded( $target, $storage ) || self::path_within_folded( $target, $base . '/cowboy-mcp' );
    }

    /**
     * Whether $path is $dir or inside it, compared the way a case-insensitive
     * filesystem (macOS, Windows) resolves names: separators unified, case folded.
     * realpath() keeps the case it was given, so `MU-Plugins/x.php` reaches the
     * real mu-plugins there. For REFUSAL checks only: on a case-sensitive host it
     * can only over-match, never let a path through.
     */
    public static function path_within_folded( string $path, string $dir ): bool {
        $norm = static fn( string $p ): string => rtrim( strtolower( str_replace( '\\', '/', $p ) ), '/' );
        $p    = $norm( $path );
        $d    = $norm( $dir );
        return '' !== $d && ( $p === $d || str_starts_with( $p . '/', $d . '/' ) );
    }

    /* ── Script-capable markup ─────────────────────────────── */

    /**
     * Markup that runs script or rewrites the page when a human views it: script-ish
     * elements, inline event handlers (also `/`-separated, `<svg/onload=…>`),
     * script / data-HTML URLs and srcdoc / formaction attributes.
     */
    private const SCRIPT_CAPABLE = '#<\s*/?\s*(script|iframe|frame|frameset|object|embed|applet|style|link|meta|base|form|svg|math|template|noscript|xml|animate|set)\b|[\s"\'/]on[a-z]+\s*=|[=\s"\'(`](?:java|vb)script\s*:|data\s*:\s*text/html|\bsrcdoc\s*=|\bformaction\s*=|\bxlink:href\s*=#i';

    /**
     * How many script-capable constructs a fragment holds, counted the way a browser
     * reads it: entities decoded (including semicolon-less numeric ones, which
     * browsers accept and html_entity_decode() does not) and the tab / newline / NUL
     * characters URL parsers drop removed first. Used both absolutely (agent-written
     * strings) and comparatively (a replace may not add any to a document).
     */
    public static function script_capable_count( string $html ): int {
        $text = (string) preg_replace_callback(
            '/&#(x[0-9a-f]+|\d+);?/i',
            static function ( array $m ): string {
                $n = ( 'x' === strtolower( $m[1][0] ) ) ? hexdec( substr( $m[1], 1 ) ) : (int) $m[1];
                // Every construct matched is ASCII; other code points cannot form one.
                return ( $n > 0 && $n < 128 ) ? chr( (int) $n ) : ' ';
            },
            $html
        );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = str_replace( [ "\t", "\n", "\r", "\0" ], '', $text );
        return max( (int) preg_match_all( self::SCRIPT_CAPABLE, $html ), (int) preg_match_all( self::SCRIPT_CAPABLE, $text ) );
    }

    /** Elements a block's `tagName` attribute must never name: tag_escape() keeps them, and they run or swallow the page. */
    private const RAW_TEXT_TAGS = [ 'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'template', 'noscript', 'noembed', 'noframes', 'textarea', 'title', 'xmp', 'plaintext', 'base', 'meta', 'link', 'svg', 'math', 'form' ];

    /**
     * Script-capable values inside block attributes (one parsed block's attrs).
     * Attributes live in the block-comment JSON, which no kses pass reads, and
     * dynamic blocks print them: string leaves are counted like markup, and a
     * `tagName` that tag_escape() resolves to a raw-text / script element counts too.
     */
    public static function attrs_risk( array $attrs ): int {
        $n = 0;
        array_walk_recursive( $attrs, static function ( $v, $k ) use ( &$n ): void {
            if ( ! is_string( $v ) ) {
                return;
            }
            if ( 'tagName' === $k && in_array( strtolower( (string) preg_replace( '/[^a-zA-Z0-9_:-]/', '', $v ) ), self::RAW_TEXT_TAGS, true ) ) {
                $n++;
            }
            $n += self::value_risk( $v, (string) $k );
        } );
        return $n;
    }

    /**
     * script_capable_count() for a named setting value, plus one when a URL-type
     * setting (`url`, `href`, `link`, `src`, `*_url`, …) holds a script URL as its whole
     * value (`javascript:x` is a link there, but prose — "JavaScript: The Good Parts" —
     * in a heading or text setting).
     */
    public static function value_risk( string $value, string $key = '' ): int {
        $n = self::script_capable_count( $value );
        if ( preg_match( '/(?:^|_)(?:url|href|link|src|action)$/i', $key ) ) {
            $flat = strtolower( (string) preg_replace( '/[\x00-\x20]+/', '', html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
            if ( str_starts_with( $flat, 'javascript:' ) || str_starts_with( $flat, 'vbscript:' ) || str_starts_with( $flat, 'data:text/html' ) ) {
                $n++;
            }
        }
        return $n;
    }

    /** attrs_risk() summed over a parsed block tree (parse_blocks() shape). */
    public static function blocks_attrs_risk( array $blocks ): int {
        $n = 0;
        foreach ( $blocks as $b ) {
            if ( ! is_array( $b ) ) {
                continue;
            }
            $n += self::attrs_risk( is_array( $b['attrs'] ?? null ) ? $b['attrs'] : [] );
            if ( ! empty( $b['innerBlocks'] ) && is_array( $b['innerBlocks'] ) ) {
                $n += self::blocks_attrs_risk( $b['innerBlocks'] );
            }
        }
        return $n;
    }

    /* ── Who administers the plugin ────────────────────────── */

    /**
     * The capability that administers Cowboy MCP: the settings page, credentials,
     * OAuth consent and Cowboy's abilities. On multisite a sub-site administrator has
     * manage_options, but wp_cli, the file tools and checkpoints reach the whole
     * network (shared wp-content, network tables), so only a super admin qualifies
     * (manage_network_options is held by super admins alone).
     */
    public static function admin_capability(): string {
        return is_multisite() ? 'manage_network_options' : 'manage_options';
    }

    /** Whether a user may administer Cowboy MCP (see admin_capability()). */
    public static function user_is_plugin_admin( int|WP_User $user ): bool {
        $user_obj = $user instanceof WP_User ? $user : get_userdata( (int) $user );
        return $user_obj && $user_obj->exists() && user_can( $user_obj, self::admin_capability() );
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

    /**
     * Whether a plugin slug or folder/file.php names this plugin — under its own
     * folder name too, for installs unzipped into a renamed folder.
     */
    public static function is_own_plugin( string $slug_or_file ): bool {
        $given = strtolower( trim( $slug_or_file ) );
        if ( '' === $given ) {
            return false;
        }
        $own        = strtolower( plugin_basename( COWBOY_MCP_PATH . 'cowboy-mcp.php' ) );
        $own_folder = strstr( $own, '/', true ) ?: $own;
        $folder     = strstr( $given, '/', true ) ?: $given;
        return $given === $own || $folder === $own_folder || $folder === 'cowboy-mcp';
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
     * them.) The two code caps below are the exception (see there).
     *
     * `unfiltered_html` is script in every admin's browser (an admin session is
     * code execution), and `create_users` hands out new accounts; on a single site
     * the stock Editor holds the former, so Editor is privileged there. Multisite
     * maps unfiltered_html to super admins only, so it does not count for a role.
     */
    private const PRIVILEGED_CAPS = [
        'manage_options',
        'install_plugins', 'edit_plugins', 'activate_plugins', 'update_plugins',
        'install_themes', 'edit_themes', 'update_themes',
        'edit_files', 'unfiltered_html',
        'edit_users', 'promote_users', 'delete_users', 'create_users',
    ];

    /** Whether granting this role hands over settings or code-execution control. */
    public static function role_is_privileged( string $role ): bool {
        $role_obj = get_role( $role );
        if ( ! $role_obj ) {
            return false;
        }
        foreach ( self::PRIVILEGED_CAPS as $cap ) {
            if ( 'unfiltered_html' === $cap && is_multisite() ) {
                continue; // map_meta_cap() keeps it from everyone but super admins
            }
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
     *
     * Reads quotes the way MySQL does: a `#` or `--` inside a string is text, and
     * stripping it would hide the live SQL after it. `--` opens a comment only before
     * whitespace or the end, so `5--1 WHERE …` stays in the scanned text. MySQL EXECUTES
     * the body of a comment opened with `/*!`, `/*!NNNNN` or MariaDB's `/*M!`, so those
     * bodies stay too: the version number is ignored on purpose, since we do not know
     * the server version, and scanning a body the server might skip only over-blocks,
     * which is the safe direction. An unclosed comment stays, for the same reason.
     *
     * Quotes follow the default sql_mode. Under NO_BACKSLASH_ESCAPES, `\'` closes a
     * string here read as open: text is then scanned, not hidden, except after a `#`
     * this reading puts outside a string.
     */
    public static function normalize_sql( string $sql ): string {
        $out  = '';
        $len  = strlen( $sql );
        $body = false;   // Inside an executed comment, whose `*/` is dropped.
        for ( $i = 0; $i < $len; ) {
            $c = $sql[ $i ];
            if ( '\'' === $c || '"' === $c || '`' === $c ) {
                $end  = self::sql_quote_end( $sql, $i );
                $out .= substr( $sql, $i, $end - $i );
                $i    = $end;
            } elseif ( '#' === $c || ( '-' === $c && '--' === substr( $sql, $i, 2 ) && ( $i + 2 >= $len || ctype_space( $sql[ $i + 2 ] ) ) ) ) {
                $i    = $i + strcspn( $sql, "\r\n", $i );
                $out .= ' ';
            } elseif ( preg_match( '#\G/\*M?!\d*#', $sql, $m, 0, $i ) ) {
                $i   += strlen( $m[0] );
                $out .= ' ';
                $body = true;
            } elseif ( $body && '*/' === substr( $sql, $i, 2 ) ) {
                $i   += 2;
                $out .= ' ';
                $body = false;
            } elseif ( '/*' === substr( $sql, $i, 2 ) && false !== strpos( $sql, '*/', $i + 2 ) ) {
                $i    = strpos( $sql, '*/', $i + 2 ) + 2;
                $out .= ' ';
            } else {
                $out .= $c;
                ++$i;
            }
        }
        // Collapse whitespace.
        return trim( (string) preg_replace( '/\s+/', ' ', $out ) );
    }

    /** The offset just past the quoted run opening at $i, or the string's end if it never closes. A doubled quote reads as two runs. */
    private static function sql_quote_end( string $sql, int $i ): int {
        $quote = $sql[ $i ];
        $len   = strlen( $sql );
        for ( ++$i; $i < $len; $i++ ) {
            if ( '\\' === $sql[ $i ] && '`' !== $quote ) {
                ++$i;
            } elseif ( $quote === $sql[ $i ] ) {
                return $i + 1;
            }
        }
        return $len;
    }

    /**
     * A (normalized) statement with each string in the given quote characters replaced
     * by '', and those strings' contents in order.
     *
     * @return array{0:string,1:string[]}
     */
    private static function sql_blank_strings( string $normalized, string $quotes ): array {
        $out     = '';
        $strings = [];
        $len     = strlen( $normalized );
        for ( $i = 0; $i < $len; ) {
            $c = $normalized[ $i ];
            if ( '\'' !== $c && '"' !== $c && '`' !== $c ) {
                $out .= $c;
                ++$i;
                continue;
            }
            $end = self::sql_quote_end( $normalized, $i );
            if ( str_contains( $quotes, $c ) ) {
                $out      .= "''";
                $strings[] = substr( $normalized, $i + 1, max( 0, $end - $i - 2 ) );
            } else {
                $out .= substr( $normalized, $i, $end - $i );
            }
            $i = $end;
        }
        return [ $out, $strings ];
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

    /**
     * Whether a (normalized) statement writes the options table without naming its rows.
     * sql_touches_secret() can only recognise a name written out, so a hex literal, an
     * option_id or a built string would pass it. Outside reads and table maintenance, the
     * options table takes one UPDATE or DELETE on that table alone, whose only WHERE is
     * option_name = 'plain_name' or IN ('plain_name', ...), neither a hard-protected name,
     * or on a DELETE, option_name LIKE a pattern that reaches none of GUARDED_OPTIONS.
     *
     * Strings don't count as code here, so `post_title='More options'` is no reason to
     * refuse. Double quotes stay in the table test: under ANSI_QUOTES they name a table.
     */
    public static function sql_writes_unnamed_option( string $normalized ): bool {
        if ( self::sql_is_single_read( $normalized ) ) {
            return false;
        }
        [ $shape, $strings ] = self::sql_blank_strings( $normalized, "'\"" );
        // PREPARE and EXECUTE IMMEDIATE build a statement no text check sees.
        if ( preg_match( '/\bPREPARE\b.*\bFROM\b|\bEXECUTE\s+IMMEDIATE\b/i', $shape ) ) {
            return true;
        }
        if ( ! self::sql_names_options_table( self::sql_blank_strings( $normalized, "'" )[0] )
            || preg_match( '/^(OPTIMIZE|ANALYZE|CHECK)\s+TABLE\s+[\w`,.\s]+;?$/i', $shape ) ) {
            return false;
        }
        $where = '/^(?:UPDATE\s+`?\w*options`?\s+SET\s[^;]*|DELETE\s+FROM\s+`?\w*options`?\s+)\bWHERE\s+`?option_name`?\s*(?:=\s*(\'\')|IN\s*\(\s*(\'\'(?:\s*,\s*\'\')*)\s*\)|LIKE\s+(\'\'))\s*;?$/i';
        if ( ! preg_match( $where, $shape, $m ) || 1 !== preg_match_all( '/\bWHERE\b/i', $shape )
            || preg_match( '/\bSET\b.*\boption_name\b.*\bWHERE\b/i', $shape ) ) {
            return true;
        }
        if ( isset( $m[3] ) ) {
            return ! str_starts_with( strtoupper( $shape ), 'DELETE' ) || self::like_reaches_guarded_option( (string) end( $strings ) );
        }
        foreach ( array_slice( $strings, -substr_count( $m[1] . ( $m[2] ?? '' ), "''" ) ) as $name ) {
            if ( ! preg_match( '/^[\w.\-]+$/D', $name ) || self::is_hard_protected_option( $name ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether SQL names a real options table of this site or its network, however it is
     * quoted, qualified or cased: a plugin's `wp_gf_addon_options` or a column called
     * `options` is not one. Whole names only, so anything around them is no way past.
     */
    private static function sql_names_options_table( string $sql ): bool {
        global $wpdb;
        $names = preg_quote( $wpdb->options, '/' );
        if ( is_multisite() ) {
            $names .= '|' . preg_quote( $wpdb->base_prefix, '/' ) . '(?:\d+_)?options';
        }
        return 1 === preg_match( '/(?<![\w$])(?:' . $names . ')(?![\w$])/i', $sql );
    }

    /** Whether a LIKE pattern could match one of GUARDED_OPTIONS, or is more than plain characters and wildcards. */
    private static function like_reaches_guarded_option( string $pattern ): bool {
        if ( ! preg_match( '/^[\w.\-%\\\\]+$/D', $pattern ) ) {
            return true;
        }
        $regex = '';
        foreach ( preg_split( '/(\\\\.|%|_)/s', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) ?: [] as $part ) {
            $regex .= match ( true ) {
                '%' === $part  => '.*',
                '_' === $part  => '.',
                '\\' === $part[0] && strlen( $part ) > 1 => preg_quote( substr( $part, 1 ), '/' ),
                default        => preg_quote( $part, '/' ),
            };
        }
        return [] !== preg_grep( '/^' . $regex . '$/i', self::GUARDED_OPTIONS );
    }

    /**
     * Whether a (normalized) query is one read statement: it starts with SELECT,
     * SHOW, DESCRIBE/DESC or EXPLAIN and has no statement separator before an
     * optional trailing one. A `;` inside a string literal also counts — refusing
     * that is the safe direction. Empty input is not a read.
     */
    public static function sql_is_single_read( string $normalized ): bool {
        $sql = rtrim( trim( $normalized ), "; \t" );
        if ( '' === $sql || str_contains( $sql, ';' ) ) {
            return false;
        }
        return (bool) preg_match( '/^\(*\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql );
    }

    /**
     * Why `wp search-replace` may not run without Power mode, or null. Pure.
     *
     * @param string[] $tables Positionals after `search-replace <old> <new>`.
     * @param string[] $flags  Flag tokens.
     */
    public static function cli_search_replace_reason( array $tables, array $flags ): ?string {
        foreach ( $flags as $flag ) {
            if ( preg_match( '/^--(all-tables(-with-prefix)?|network)(=|$)/i', (string) $flag ) ) {
                return 'the all-tables and network flags reach the options table.';
            }
        }
        if ( [] === $tables ) {
            return 'without a table list it rewrites every core table, options included. Name the tables.';
        }
        foreach ( $tables as $table ) {
            $name = strtolower( (string) $table );
            if ( ! preg_match( '/^[a-z0-9_$]+$/', $name ) ) {
                return "table '{$table}' must be a plain table name (no wildcards).";
            }
            if ( str_contains( $name, 'cowboy_mcp' ) || preg_match( '/(options|users|usermeta|sitemeta|site|blogs|blogmeta)$/', $name ) ) {
                return "table '{$table}' holds options, users or network data.";
            }
        }
        return null;
    }

    /**
     * Whether a (normalized) read query reaches option, user-meta or network-meta
     * values: it names such a table AND the value column, or selects `*` / `t.*`.
     * `COUNT(*)` is not a star select. Over-matching (a column merely called
     * `options`, a multiplication) only refuses, which is the safe direction.
     */
    public static function sql_reads_option_values( string $normalized ): bool {
        if ( ! preg_match( '/(options|sitemeta|usermeta)\b/i', $normalized ) ) {
            return false;
        }
        if ( preg_match( '/\b(option_value|meta_value)\b/i', $normalized ) ) {
            return true;
        }
        $no_count = (string) preg_replace( '/\(\s*\*\s*\)/', '()', $normalized );
        return str_contains( $no_count, '*' );
    }

    /**
     * Why a `db` command's flags may not run, or null. mysql accepts any
     * unambiguous prefix of a long option and the loose-/maximum- prefixes, so
     * names are matched as prefixes after those are removed. `--defaults` and
     * `--no-defaults` are WP-CLI's own flags. Pure.
     *
     * @param string[] $flags Flag tokens.
     */
    public static function cli_db_flag_reason( array $flags ): ?string {
        $risky = [ 'execute', 'init-command', 'defaults-file', 'defaults-extra-file', 'defaults-group-suffix', 'login-path', 'plugin-dir', 'local-infile', 'load-data-local-dir' ];
        foreach ( $flags as $flag ) {
            $name = strtolower( str_replace( '_', '-', (string) substr( (string) strstr( $flag . '=', '=', true ), 2 ) ) );
            while ( preg_match( '/^(loose|maximum)-(.+)$/', $name, $m ) ) {
                $name = $m[2];
            }
            if ( '' === $name || 'defaults' === $name || 'no-defaults' === $name ) {
                continue;
            }
            foreach ( $risky as $option ) {
                if ( str_starts_with( $option, $name ) ) {
                    return "the mysql option --{$option} runs SQL or loads an option file outside these checks.";
                }
            }
        }
        return null;
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

    /** wp-config constants that hold secrets (salts, DB password, anything named like a secret). */
    private const CLI_SECRET_CONSTANTS = '/^(DB_PASSWORD|(AUTH|SECURE_AUTH|LOGGED_IN|NONCE)_(KEY|SALT))$|SECRET|PASSWORD|TOKEN|_API_KEY$/i';

    /**
     * Why a WP-CLI command would print a credential, or null. Pure (no WP calls).
     * Scans every word the command carries — positionals AND flag values
     * (`--fields=ID,user_pass`, `--constant=AUTH_KEY`) — so flag placement and
     * quoting cannot hide the secret's name. Read-shaped commands only: user writes
     * (`user update --user_pass=…`) are not credential reads and stay with the rest of
     * the wp_cli escape hatch.
     */
    public static function cli_secret_read_reason( array $positionals, array $tokens ): ?string {
        $noun  = isset( $positionals[0] ) ? strtolower( $positionals[0] ) : '';
        $verb  = isset( $positionals[1] ) ? strtolower( $positionals[1] ) : '';
        $words = [];
        foreach ( $tokens as $tok ) {
            foreach ( preg_split( '/[=,\s]+/', (string) $tok ) as $w ) {
                $w = ltrim( $w, '-' );
                if ( '' !== $w ) {
                    $words[] = $w;
                }
            }
        }
        if ( 'config' === $noun ) {
            if ( 'list' === $verb ) {
                return 'config list prints every wp-config constant, including the salts and DB_PASSWORD.';
            }
            if ( 'get' === $verb ) {
                foreach ( $words as $w ) {
                    if ( preg_match( self::CLI_SECRET_CONSTANTS, $w ) ) {
                        return "config get {$w} would print a secret wp-config constant.";
                    }
                }
            }
            return null;
        }
        if ( 'user' === $noun ) {
            if ( 'application-password' === $verb ) {
                $sub = isset( $positionals[2] ) ? strtolower( $positionals[2] ) : '';
                if ( 'create' === $sub ) {
                    return 'application-password create prints a new plaintext credential.';
                }
                if ( in_array( 'password', array_map( 'strtolower', $words ), true ) ) {
                    return 'The application password field holds a credential hash.';
                }
                return null;
            }
            if ( in_array( $verb, [ 'list', 'get', 'meta' ], true ) ) {
                foreach ( $words as $w ) {
                    if ( in_array( strtolower( $w ), [ 'user_pass', 'user_activation_key', 'session_tokens' ], true ) ) {
                        return "The {$w} field holds a credential.";
                    }
                }
            }
            return null;
        }
        if ( 'option' === $noun && 'list' === $verb ) {
            // `option list` prints option_value for every match (secrets of any plugin
            // included, under any name or --search pattern). Names only are fine.
            $fields = null;
            foreach ( $tokens as $tok ) {
                if ( preg_match( '/^--fields?=(.*)$/i', (string) $tok, $m ) ) {
                    $fields = strtolower( $m[1] );
                }
                if ( preg_match( '/^--format=(count|total_bytes)$/i', (string) $tok ) ) {
                    $fields = 'option_name';
                }
            }
            if ( null === $fields || preg_match( '/(^|,)\s*(option_)?value\s*(,|$)/', $fields ) ) {
                return 'option list prints every matching option value. List names with --field=option_name, then option get the ones you need.';
            }
            return null;
        }
        if ( 'option' === $noun && in_array( $verb, [ 'get', 'pluck' ], true ) ) {
            // Confidentiality only: write-protected but public options (siteurl, home,
            // active_plugins…) stay readable.
            foreach ( array_slice( $words, 2 ) as $w ) {
                $name = strtolower( $w );
                // Secret-bearing settings blobs too: shell output cannot be redacted
                // (wordpress://options/{name} returns them with the secrets masked).
                if ( self::is_hard_protected_option( $name )
                    || preg_match( self::SECRET_OPTION_NAMES, $name )
                    || preg_match( self::SECRET_BEARING_OPTIONS, $name )
                    || preg_match( '/^woocommerce_.+_settings$/', $name ) ) {
                    return "Option {$w} holds a credential or Cowboy MCP internals.";
                }
            }
            return null;
        }
        if ( 'transient' === $noun ) {
            foreach ( array_slice( $words, 2 ) as $w ) {
                // A --search pattern (`cowboy*`, `%mcp%`) counts when it could match one of ours.
                $re = '/^' . strtr( preg_quote( strtolower( $w ), '/' ), [ '\\*' => '.*', '%' => '.*', '\\?' => '.' ] ) . '$/';
                foreach ( [ 'cowboy_mcp_new_key_1', '_transient_cowboy_mcp_oauth_reg_open' ] as $ours ) {
                    if ( str_starts_with( strtolower( $w ), 'cowboy_mcp_' ) || preg_match( '/^(_site)?_transient_(timeout_)?cowboy_mcp_/i', $w )
                        || ! self::is_plain_name( $w )
                        || ( strpbrk( $w, '*%?' ) && preg_match( $re, $ours ) ) ) {
                        return "Transient {$w} belongs to Cowboy MCP (sign-in codes, one-time keys).";
                    }
                }
            }
        }
        return null;
    }

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
     * "eval"). It does not perform variable/command substitution. The wp_cli
     * handler executes exactly these tokens (each escapeshellarg()'d — see
     * cli_shell_command()), so what the gates judge is what runs.
     *
     * Flags are classified the way WP-CLI's Configurator::extract_assoc() does
     * AFTER the shell has removed the quotes: a token is a flag when it starts
     * with `--` and one more character that is not `=`, however it was quoted
     * (`'--require=x'` is the flag --require to WP-CLI). Everything else,
     * including a lone `--`, `--=x` and single-dash tokens, is positional.
     *
     * @return array{tokens:string[],positionals:string[],flags:string[],unterminated:bool}
     */
    public static function cli_tokens( string $command ): array {
        $tokens       = [];
        $current      = '';
        $in_token     = false;
        $unterminated = false;
        $len          = strlen( $command );
        $i            = 0;

        while ( $i < $len ) {
            $c = $command[ $i ];

            if ( $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" ) {
                if ( $in_token ) {
                    $tokens[] = $current;
                    $current  = '';
                    $in_token = false;
                }
                $i++;
                continue;
            }

            if ( $c === "'" ) {
                // Single quotes: everything up to the next ' is literal.
                $in_token = true;
                $i++;
                while ( $i < $len && $command[ $i ] !== "'" ) {
                    $current .= $command[ $i ];
                    $i++;
                }
                if ( $i >= $len ) {
                    $unterminated = true;
                }
                $i++; // consume the closing quote (or run off the end if unterminated)
                continue;
            }

            if ( $c === '"' ) {
                // Double quotes: backslash escapes \, ", $, ` — everything else literal.
                $in_token = true;
                $i++;
                while ( $i < $len && $command[ $i ] !== '"' ) {
                    if ( $command[ $i ] === '\\' && $i + 1 < $len
                        && in_array( $command[ $i + 1 ], [ '"', '\\', '$', '`' ], true ) ) {
                        $current .= $command[ $i + 1 ];
                        $i       += 2;
                    } else {
                        $current .= $command[ $i ];
                        $i++;
                    }
                }
                if ( $i >= $len ) {
                    $unterminated = true;
                }
                $i++; // consume the closing quote
                continue;
            }

            if ( $c === '\\' ) {
                // Outside quotes: backslash escapes the very next character.
                $in_token = true;
                if ( $i + 1 < $len ) {
                    $current .= $command[ $i + 1 ];
                    $i       += 2;
                } else {
                    $i++;
                }
                continue;
            }

            $in_token = true;
            $current .= $c;
            $i++;
        }
        if ( $in_token ) {
            $tokens[] = $current;
        }

        $positionals = [];
        $flags       = [];
        foreach ( $tokens as $token ) {
            if ( self::cli_is_flag( $token ) ) {
                $flags[] = $token;
            } else {
                $positionals[] = $token;
            }
        }

        return [ 'tokens' => $tokens, 'positionals' => $positionals, 'flags' => $flags, 'unterminated' => $unterminated ];
    }

    /** Whether WP-CLI reads an argv word as an associative (--flag) argument. Mirrors Configurator::extract_assoc(). */
    public static function cli_is_flag( string $token ): bool {
        return (bool) preg_match( '/^--[^=]/', $token );
    }

    /**
     * The shell command line for a gated WP-CLI call: `wp` plus every token
     * cli_tokens() produced, each escapeshellarg()'d, so the shell hands WP-CLI
     * exactly the words the gates judged (no line continuations, no globbing,
     * no expansion). `--path` is appended unless the caller passed one (Power
     * mode only; the gate refuses it otherwise).
     */
    public static function cli_shell_command( string $command, string $wp_path ): string {
        $t     = self::cli_tokens( $command );
        $words = array_map( 'escapeshellarg', $t['tokens'] );
        if ( ! preg_grep( '/^--path(=|$)/i', $t['flags'] ) ) {
            $words[] = escapeshellarg( '--path=' . $wp_path );
        }
        return 'wp ' . implode( ' ', $words ) . ' --allow-root 2>&1';
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

        // 00. Control characters (NUL, ESC, vertical tab, …) and unbalanced quotes
        //     have no legitimate use here and are where shells and tokenizers
        //     disagree. Never lifted: every check below relies on reading the
        //     command exactly as WP-CLI will. Tab, CR and LF are ordinary
        //     whitespace to cli_tokens() and inside quotes (multi-line SQL).
        if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $command ) ) {
            return [
                'blocked' => true,
                'code'    => 'blocked',
                'message' => 'The command contains control characters.',
            ];
        }
        $t           = self::cli_tokens( $command );
        $positionals = $t['positionals'];
        if ( $t['unterminated'] ) {
            return [
                'blocked' => true,
                'code'    => 'blocked',
                'message' => 'The command has an unterminated quote.',
            ];
        }

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
        //     so rejecting it loses nothing. A lone `--` and single-dash words are
        //     positionals to WP-CLI (see cli_tokens()), so without this they would
        //     shift the option guard / blocked-paths anchors by one slot (e.g.
        //     `-- option update cowboy_mcp_settings x`). Unconditional — never lifted
        //     by Power mode — for the same reason as the @alias check.
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
        //    The plugin's transients (registration window, auth codes, CIMD document cache)
        //    are options too — `_transient_cowboy_mcp_*` via `option`, or `cowboy_mcp_*`
        //    via `transient set|delete` — and are covered the same way.
        $noun = isset( $positionals[0] ) ? strtolower( $positionals[0] ) : '';
        if ( 'option' === $noun || 'transient' === $noun ) {
            $verb           = isset( $positionals[1] ) ? strtolower( $positionals[1] ) : '';
            $name           = isset( $positionals[2] ) ? strtolower( $positionals[2] ) : '';
            // A non-ASCII name can collate onto one of ours (see is_plain_name()).
            $own            = str_starts_with( $name, 'cowboy_mcp_' ) || (bool) preg_match( '/^(_site)?_transient_(timeout_)?cowboy_mcp_/', $name )
                || ( '' !== $name && ! self::is_plain_name( $name ) );
            $is_write_verb  = in_array( $verb, [ 'update', 'delete', 'add', 'set' ], true ) && $own;
            // `option patch <action> <name> …` carries the name one slot later.
            $patch_name     = isset( $positionals[3] ) ? (string) $positionals[3] : '';
            $is_patch_touch = 'option' === $noun && $verb === 'patch'
                && ( self::cli_tokens_contain( $t['tokens'], 'cowboy_mcp_' ) || ( '' !== $patch_name && ! self::is_plain_name( $patch_name ) ) );
            if ( $is_write_verb || $is_patch_touch ) {
                return [
                    'blocked' => true,
                    'code'    => 'blocked',
                    'message' => 'WP-CLI writes to the plugin\'s own cowboy_mcp_ options are blocked for safety and cannot be lifted by Power mode.',
                ];
            }
        }

        // 2d. With a persistent object cache, get_option() reads the cache first, so a
        //     cache write to the options groups is an option write. Same footing as
        //     step 2 (never lifted).
        if ( 'cache' === $noun ) {
            $cverb = isset( $positionals[1] ) ? strtolower( $positionals[1] ) : '';
            if ( in_array( $cverb, [ 'set', 'add', 'replace', 'patch', 'incr', 'decr' ], true ) ) {
                foreach ( array_slice( $t['tokens'], 2 ) as $tok ) {
                    $w = strtolower( (string) $tok );
                    if ( in_array( $w, [ 'options', 'site-options', 'alloptions', 'notoptions' ], true )
                        || str_contains( $w, 'cowboy_mcp' ) || ! self::is_plain_name( $w ) ) {
                        return [
                            'blocked' => true,
                            'code'    => 'blocked',
                            'message' => 'WP-CLI cache writes to the options cache are blocked: they change what get_option() returns.',
                        ];
                    }
                }
            }
        }

        // 2c. Never let the agent switch this plugin off, Power mode included: the
        //     connection drops mid-call and only a human in wp-admin can bring it back.
        //     Pure-function rule: matches the `cowboy-mcp` folder slug (a renamed-folder
        //     install is still covered by the wp_deactivate_plugin handler guard).
        if ( 'plugin' === $noun ) {
            $pverb = isset( $positionals[1] ) ? strtolower( $positionals[1] ) : '';
            if ( in_array( $pverb, [ 'deactivate', 'delete', 'uninstall', 'toggle' ], true ) ) {
                $hits_self = false;
                foreach ( array_slice( $positionals, 2 ) as $p ) {
                    $first = strtolower( strstr( $p, '/', true ) ?: $p );
                    if ( 'cowboy-mcp' === $first ) {
                        $hits_self = true;
                    }
                }
                if ( ! $hits_self && preg_grep( '/^--all(=.*)?$/i', $t['flags'] ) ) {
                    $hits_self = true;
                    foreach ( $t['flags'] as $flag ) {
                        if ( preg_match( '/^--exclude=(.*)$/i', $flag, $m ) ) {
                            $excluded = array_map( fn( $s ) => strtolower( trim( $s ) ), explode( ',', $m[1] ) );
                            if ( in_array( 'cowboy-mcp', $excluded, true ) ) {
                                $hits_self = false;
                            }
                        }
                    }
                }
                if ( $hits_self ) {
                    return [
                        'blocked' => true,
                        'code'    => 'self_target',
                        'message' => 'Refusing to deactivate or remove Cowboy MCP itself: this connection would drop and only wp-admin could bring it back. Use --exclude=cowboy-mcp with --all.',
                    ];
                }
            }
        }

        // 2b. Commands that would print a credential (salts, DB password, password
        //     hashes, API keys, Cowboy's one-time keys and sign-in codes). Never lifted
        //     by Power mode — same footing as sql_touches_secret() in step 4.
        $secret = self::cli_secret_read_reason( $positionals, $t['tokens'] );
        if ( null !== $secret ) {
            return [
                'blocked' => true,
                'code'    => 'secret_read',
                'message' => "Blocked: this command would print a credential. {$secret}",
            ];
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
        // 3b. `db` commands hand their unknown --flags to mysql / mysqlcheck, and
        //     some of those carry SQL or load an option file that can (--execute,
        //     --init-command, --defaults-extra-file …) — SQL the checks in step 4
        //     never see. Never lifted, for the same reason as step 4's secret check.
        if ( 'db' === $noun ) {
            $why = self::cli_db_flag_reason( $t['flags'] );
            if ( null !== $why ) {
                return [ 'blocked' => true, 'code' => 'blocked', 'message' => "Blocked: {$why}" ];
            }
        }

        if ( isset( $positionals[0], $positionals[1] )
            && strtolower( $positionals[0] ) === 'db'
            && in_array( strtolower( $positionals[1] ), [ 'query', 'search' ], true ) ) {
            $is_search = 'search' === strtolower( $positionals[1] );
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
            // `db search <pattern> [<tables>…]` names its tables after the pattern;
            // they are judged in step 4c.
            $sql_positionals = array_slice( $positionals, 2, $is_search ? 1 : null );
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
            // Without Power mode the escape hatch reads only. Recognising protected
            // option names inside free-form SQL is not reliable, and a write here would
            // reach cowboy_mcp_settings (the bootstrap invariant) and credential rows.
            if ( ! $power_mode && 'query' === strtolower( $positionals[1] ) && ! self::sql_is_single_read( $sql ) ) {
                return [
                    'blocked' => true,
                    'code'    => 'blocked',
                    'message' => 'Without Power mode, db query runs a single read statement only (SELECT, SHOW, DESCRIBE, EXPLAIN). Use the dedicated tools to change data.',
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
            // Not lifted by Power mode either: a write to the options table must name its
            // rows, so the check above can read them. This judges one SQL text. Power mode
            // keeps other routes to those rows, such as wp_write_file and search-replace.
            if ( ! $is_search && self::sql_writes_unnamed_option( $sql ) ) {
                return [ 'blocked' => true, 'code' => 'blocked', 'message' => "A write to the options table must be one UPDATE or DELETE whose only WHERE names its rows: option_name = 'name', IN ('name', ...) or, on a DELETE, LIKE 'pattern'. Use wp option for anything else." ];
            }
            // 4c. Option and user/network meta VALUES hold third-party secrets (API
            //     keys, SMTP passwords, licence keys, session tokens) that every other
            //     read path redacts by name; shell output cannot be redacted. Without
            //     Power mode, db query may not read those value columns and db search
            //     must name its tables, none of them holding options or user data.
            if ( ! $power_mode && ! $is_search && self::sql_reads_option_values( $sql ) ) {
                return [ 'blocked' => true, 'code' => 'blocked', 'message' => 'Without Power mode, db query may not read option, user-meta or network-meta values (they hold credentials and cannot be redacted here). Name the columns you need other than option_value/meta_value, or use wp_cli option get, the wordpress://options resources or wp_db_health_report.' ];
            }
            if ( ! $power_mode && $is_search ) {
                $why = self::cli_search_replace_reason( array_slice( $positionals, 3 ), $t['flags'] );
                if ( null !== $why ) {
                    return [ 'blocked' => true, 'code' => 'blocked', 'message' => "db search blocked: it prints the matching values, and {$why} An administrator can enable Power mode to allow this." ];
                }
            }
        }

        // 4b. search-replace rewrites whole tables, options included. Without Power
        //     mode it must name its tables, and none may hold options, users, user
        //     meta or network data (or be one of ours); wildcards and the
        //     all-tables/network flags are refused.
        if ( ! $power_mode && 'search-replace' === $noun ) {
            $why = self::cli_search_replace_reason( array_slice( $positionals, 3 ), $t['flags'] );
            if ( null !== $why ) {
                return [ 'blocked' => true, 'code' => 'blocked', 'message' => "search-replace blocked: {$why} An administrator can enable Power mode to allow this; wp_search_replace covers post content." ];
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
        // OAuth tokens are `cmcp_at_<id>.<secret>`: the secret half follows the dot.
        $text = preg_replace( '/\bcmcp_(?:at|rt)_[A-Za-z0-9_\-]+(?:\.[A-Za-z0-9_\-]+)?/', '[REDACTED]', (string) $text );
        // Credentials in URLs: userinfo (user:pass@, Sentry-style key@) and webhook paths.
        $text = preg_replace( '#\b([a-z][a-z0-9+.\-]*://)(?:[^\s/@:"\'<>]+:[^\s/@"\'<>]+|[A-Za-z0-9_\-]{16,})@#i', '$1[REDACTED]@', (string) $text );
        $text = preg_replace( '#\b(hooks\.slack\.com/(?:services|workflows|triggers)/|discord(?:app)?\.com/api/webhooks/)[^\s"\'<>]+#i', '$1[REDACTED]', (string) $text );
        return (string) $text;
    }

    /**
     * Mask secret-looking query values in a logged URL (password-reset `key=`,
     * `token=`, signatures, one-time codes), on top of scrub_secrets(). Display only.
     */
    public static function redact_url_query( string $url ): string {
        return (string) preg_replace_callback(
            '/([?&;])([^=&#\s]+)=([^&#\s]*)/',
            static function ( array $m ): string {
                $name = strtolower( (string) preg_replace( '/[^a-z]/i', '', rawurldecode( $m[2] ) ) );
                return preg_match( '/key|token|pass|pwd|secret|auth|code|nonce|sig|session|hash|otp|ticket|credential/', $name )
                    ? $m[1] . $m[2] . '=[REDACTED]'
                    : $m[0];
            },
            self::scrub_secrets( $url )
        );
    }

    /**
     * Mask the string literals in a SQL statement shown to an agent (another
     * request's query in the process list may carry a password, a token or a
     * user's personal data). Display only.
     */
    public static function redact_sql_literals( string $sql ): string {
        return (string) preg_replace( [ "/'(?:[^'\\\\]|\\\\.|'')*'/s", '/"(?:[^"\\\\]|\\\\.|"")*"/s' ], [ "'?'", '"?"' ], $sql );
    }

    /** wp-config constants whose exact values are redacted from WP-CLI output. */
    private const SECRET_CONSTANTS = [ 'DB_PASSWORD', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT', 'WP_SECRETS_KEY', 'WP_SECRETS_KEY_PREVIOUS' ];

    /**
     * Backstop for wp_cli output: redact what the gate cannot predict from the
     * command line (e.g. `user application-password list` prints hashes by default).
     * Covers password hashes (WordPress bcrypt, bcrypt, phpass, argon2, the 6.8+
     * `$generic$` fast hash — raw or JSON-escaped), Cowboy tokens/keys, and the exact
     * values of the wp-config secrets.
     */
    public static function scrub_cli_output( string $text ): string {
        $text = self::scrub_secrets( $text );
        $text = (string) preg_replace(
            [
                '/\$wp\$2[aby]\$\d{2}\$[^\s"\',]+/',
                '/\$2[aby]\$\d{2}\$[^\s"\',]+/',
                '/\$[PH]\$[^\s"\',]{31,}/',
                '/\$argon2(?:id|i|d)\$v=\d+\$[^\s"\']+/',
                '/\$generic\$[^\s"\',]+/',
            ],
            '[REDACTED]',
            $text
        );
        foreach ( self::SECRET_CONSTANTS as $c ) {
            $v = defined( $c ) ? (string) constant( $c ) : '';
            // Skip short and all-letter values: a dev DB_PASSWORD such as "password" or
            // "wordpress" would otherwise be cut out of slugs, URLs and prose.
            if ( strlen( $v ) < 8 || preg_match( '/^[a-z]+$/i', $v ) ) {
                continue;
            }
            foreach ( array_unique( [ $v, substr( (string) wp_json_encode( $v ), 1, -1 ) ] ) as $needle ) {
                $text = (string) preg_replace( '/(?<![\w-])' . preg_quote( $needle, '/' ) . '(?![\w-])/', '[REDACTED]', $text );
            }
        }
        return $text;
    }

    /* ── SSRF URL validation ───────────────────────────────── */

    /**
     * Validate a URL for outbound fetches: http(s) only, and every resolved IP
     * (all A + AAAA records) must be public. Rejects private, reserved, loopback,
     * link-local (cloud metadata 169.254.0.0/16) and unique-local addresses.
     *
     * @param bool $always Keep the address check on in Power mode (chat file links).
     * @return bool|WP_Error
     */
    public static function validate_url_ssrf( string $url, bool $always = false ): bool|WP_Error {
        $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
            return new WP_Error( 'invalid_scheme', 'Only http and https URLs are allowed.' );
        }

        // Power mode lifts the private/reserved-IP rejection. Scheme validation (above)
        // still applies; the transport is also switched to the unguarded variant at the
        // call sites so WordPress does not re-block the request.
        if ( ! $always && self::power_mode_enabled() ) {
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
        // PHP's range flags leave these out (and differ across 8.x): CGNAT (also
        // Alibaba's metadata address and Tailscale), benchmarking, IETF/TEST-NETs,
        // multicast, IPv6 site-local/multicast/documentation/discard.
        foreach ( self::NON_PUBLIC_CIDRS as $cidr ) {
            if ( self::ip_in_cidr( $ip, $cidr ) ) {
                return false;
            }
        }
        // Addresses that carry an IPv4 address inside (NAT64, 6to4, IPv4-compatible)
        // reach that IPv4 address: judge it instead.
        $embedded = self::embedded_ipv4( $ip );
        if ( null !== $embedded ) {
            return self::ip_is_public( $embedded );
        }
        return true;
    }

    /** Ranges ip_is_public() refuses on top of PHP's NO_PRIV_RANGE / NO_RES_RANGE. */
    private const NON_PUBLIC_CIDRS = [
        '0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        'fe80::/10', 'fec0::/10', 'fc00::/7', 'ff00::/8', '2001:db8::/32', '100::/64', '2001::/32',
    ];

    /** Whether an IP address is inside a CIDR block of the same family. */
    private static function ip_in_cidr( string $ip, string $cidr ): bool {
        [ $net, $bits ] = explode( '/', $cidr );
        $a = @inet_pton( $ip );
        $b = @inet_pton( $net );
        if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) ) {
            return false;
        }
        $bits  = (int) $bits;
        $bytes = intdiv( $bits, 8 );
        if ( substr( $a, 0, $bytes ) !== substr( $b, 0, $bytes ) ) {
            return false;
        }
        $rest = $bits % 8;
        if ( 0 === $rest ) {
            return true;
        }
        $mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
        return ( ord( $a[ $bytes ] ) & $mask ) === ( ord( $b[ $bytes ] ) & $mask );
    }

    /** The IPv4 address inside a NAT64 (64:ff9b::/96, 64:ff9b:1::/48), 6to4 (2002::/16) or IPv4-compatible (::/96) address, or null. */
    private static function embedded_ipv4( string $ip ): ?string {
        $bin = @inet_pton( $ip );
        if ( false === $bin || 16 !== strlen( $bin ) ) {
            return null;
        }
        if ( self::ip_in_cidr( $ip, '64:ff9b::/96' ) || self::ip_in_cidr( $ip, '64:ff9b:1::/48' ) || self::ip_in_cidr( $ip, '::/96' ) ) {
            return (string) inet_ntop( substr( $bin, 12, 4 ) );
        }
        if ( self::ip_in_cidr( $ip, '2002::/16' ) ) {
            return (string) inet_ntop( substr( $bin, 2, 4 ) );
        }
        return null;
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
