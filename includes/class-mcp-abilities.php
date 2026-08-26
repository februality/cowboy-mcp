<?php
/**
 * Cowboy MCP – WordPress Abilities API bridge (outbound half).
 *
 * Registers every exposable Cowboy tool as a `cowboy-mcp/*` ability so the
 * core REST endpoint (/wp-json/wp-abilities/v1), WP-CLI (`wp ability run`),
 * MCP adapters and AI agents can call it. Every call is dispatched through
 * Cowboy_MCP_Tools::call_tool(), so allowed_tools, per-credential scope,
 * dry-run, safe mode, the audit log and the undo journal apply to whoever calls.
 *
 * Definitions come from a cached index (option cowboy_mcp_ability_index) so
 * registration never has to load the domain files (~35 ms) on requests where
 * another plugin instantiates the registry (bundled mcp-adapters do so on every
 * REST request). Execution always resolves the live handler with live gates,
 * so the index only ever affects discovery.
 *
 * Every WordPress 6.9+ function used here is guarded by function_exists() in
 * THIS file: the plugin's `Requires at least` is 6.2 and Plugin Check's
 * compatibility scan honours same-file guards. On older cores the class is inert.
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Abilities {

    const OPTION_INDEX = 'cowboy_mcp_ability_index';
    const NAMESPACE    = 'cowboy-mcp';
    const CATEGORY     = 'cowboy-mcp';

    /** Tools exposed only while Power mode is on (escalation-equivalent). */
    private const POWER_MODE_ONLY = [ 'wp_cli', 'wp_write_file' ];

    /** Never exposed: consumers have their own discovery, and cowboy_run would be a second dispatch path. */
    private const NEVER = [ 'cowboy_run', 'cowboy_discover' ];

    /** @var array<string, array>|null ability name => index row, for this request. */
    private static ?array $index = null;

    /** @var array<string, array> tool name => index row, filled at registration; is_exposable() reads it. */
    private static array $by_tool = [];

    /** @var array<string, string> tool name => ability name, registered this request. */
    private static array $registered = [];

    /** @var int Ability executions in flight (nesting depth). */
    private static int $transport_depth = 0;

    private static bool $rebuilt_now          = false;
    private static bool $persist_fail_logged  = false;

    public static function init(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }
        add_action( 'wp_abilities_api_categories_init', [ __CLASS__, 'register_category' ] );
        add_action( 'wp_abilities_api_init', [ __CLASS__, 'register_outbound' ] );
        add_filter( 'wp_get_abilities_item_include', [ __CLASS__, 'filter_visibility' ], 10, 2 );
    }

    /* ── Settings ─────────────────────────────────────────── */

    /** Outbound switch: the MCP-server master switch is a kill switch for every transport. */
    public static function enabled(): bool {
        $s = Cowboy_MCP_Tools::get_settings();
        return ! empty( $s['enabled'] ) && ! empty( $s['abilities_expose'] ?? true );
    }

    /* ── Registration ─────────────────────────────────────── */

    public static function register_category(): void {
        if ( ! function_exists( 'wp_register_ability_category' ) ) {
            return;
        }
        wp_register_ability_category( self::CATEGORY, [
            'label'       => 'Cowboy MCP',
            'description' => 'Guarded, undoable WordPress operations provided by Cowboy MCP.',
        ] );
    }

    /**
     * Register every exposable tool as an ability. Runs inside wp_abilities_api_init.
     */
    public static function register_outbound(): void {
        if ( ! function_exists( 'wp_register_ability' ) || ! self::enabled() ) {
            return;
        }
        $failed = [];
        foreach ( self::index() as $ability_name => $row ) {
            // A tampered index row must not relabel an ability or poison $by_tool
            // (is_exposable() and the execution shim's schema both read it).
            if ( ! self::row_is_consistent( (string) $ability_name, (array) $row ) ) {
                $failed[] = $ability_name . ' (inconsistent index row)';
                continue;
            }
            $tool                   = (string) $row['tool'];
            self::$by_tool[ $tool ] = $row;
            if ( ! self::is_exposable( $tool ) ) {
                continue;
            }
            $schema            = $row['input_schema'];
            $schema['default'] = (object) [];   // a bare run (no input) must validate as an object
            $readonly          = ! empty( $row['annotations']['readOnlyHint'] );

            $ability = wp_register_ability( $ability_name, [
                'label'               => (string) $row['label'],
                'description'         => (string) $row['description'],
                'category'            => self::CATEGORY,
                'input_schema'        => $schema,
                'execute_callback'    => static fn( $input = null ) => self::execute_tool( $tool, $input ),
                'permission_callback' => static fn() => self::can_execute( $tool ),
                'meta'                => [
                    'annotations'  => [
                        'readonly'    => $readonly,
                        'destructive' => ! empty( $row['annotations']['destructiveHint'] ),
                        'idempotent'  => ! empty( $row['annotations']['idempotentHint'] ),
                    ],
                    'public'       => true,
                    'show_in_rest' => true,
                    'mcp'          => [ 'public' => true, 'type' => 'tool' ],   // mcp-adapter <= 0.5 reads this; 0.6 reads `public`
                    'provider'     => 'Cowboy MCP',                             // AI plugin Abilities Explorer provider column
                    'cowboy_mcp'   => [
                        'tool'        => $tool,
                        'category'    => (string) $row['category'],
                        'undoable'    => (bool) $row['undoable'],
                        'http_method' => (string) $row['http_method'],
                    ],
                ],
            ] );
            if ( $ability ) {
                self::$registered[ $tool ] = $ability_name;
            } else {
                $failed[] = $tool;
            }
        }
        if ( $failed ) {
            // One row per registration pass: a systemic failure would otherwise write ~170.
            Cowboy_MCP_Auth::log( 'ability_register_failed', [ 'count' => count( $failed ), 'tools' => implode( ',', $failed ) ] );
        }
    }

    /**
     * Whether an index row's `tool` really derives the ability name it is filed
     * under. The index is a stored option: without this, editing one row could
     * point a trusted ability name (cowboy-mcp/wp-site-info) at another tool.
     */
    public static function row_is_consistent( string $ability_name, array $row ): bool {
        $tool = (string) ( $row['tool'] ?? '' );
        return '' !== $tool && $ability_name === self::NAMESPACE . '/' . str_replace( '_', '-', $tool );
    }

    /** tool name => ability name registered during this request. */
    public static function registered(): array {
        return self::$registered;
    }

    /* ── Policy ───────────────────────────────────────────── */

    /**
     * Outbound tool policy (spec §6.4): a real static-domain tool, permitted by
     * allowed_tools, and — for wp_cli / wp_write_file — only with Power mode.
     * Evaluated at registration, in the permission callback, and by call_tool()
     * for every nested dispatch while an ability execution is in flight.
     */
    public static function is_exposable( string $tool ): bool {
        if ( ! isset( self::$by_tool[ $tool ] ) || in_array( $tool, self::NEVER, true ) ) {
            return false;
        }
        if ( ( self::$by_tool[ $tool ]['category'] ?? '' ) === 'abilities' ) {
            return false;
        }
        $allowed = Cowboy_MCP_Tools::get_settings()['allowed_tools'] ?? 'all';
        if ( $allowed !== 'all' && is_array( $allowed ) && ! in_array( $tool, $allowed, true ) ) {
            return false;
        }
        if ( in_array( $tool, self::POWER_MODE_ONLY, true ) && ! Cowboy_MCP_Security::power_mode_enabled() ) {
            return false;
        }
        return true;
    }

    /** Ability permission callback. Plain bool — a WP_Error here makes WP_Ability::execute() _doing_it_wrong(). */
    public static function can_execute( string $tool ): bool {
        return self::enabled() && current_user_can( 'manage_options' ) && self::is_exposable( $tool );
    }

    /** True while an ability execution is on the call stack (nested dispatch policy). */
    public static function transport_active(): bool {
        return self::$transport_depth > 0;
    }

    /**
     * Hide cowboy-mcp/* from users without manage_options in every
     * wp_get_abilities() consumer (REST list, adapter discover-abilities...).
     * Core lists show_in_rest abilities to any `read` user; Cowboy's catalog is
     * admin-only everywhere else.
     *
     * ENUMERATION only. Core's single-item route GET /wp-abilities/v1/abilities/
     * {name} resolves through wp_has_ability()/wp_get_ability(), which never run
     * this filter, so a `read` user who guesses a name still sees its label,
     * description and schema. Execution is gated separately by can_execute().
     */
    public static function filter_visibility( $include, $ability ) {
        if ( $include && is_object( $ability ) && method_exists( $ability, 'get_name' )
            && str_starts_with( (string) $ability->get_name(), self::NAMESPACE . '/' )
            && ! current_user_can( 'manage_options' ) ) {
            return false;
        }
        return $include;
    }

    /* ── Definitions index ────────────────────────────────── */

    /** Cached index for this request; validated against the domain signature, rebuilt on mismatch. */
    private static function index(): array {
        if ( self::$index !== null ) {
            return self::$index;
        }
        $signature = Cowboy_MCP_Tools::domain_signature();
        $stored    = get_option( self::OPTION_INDEX );
        if ( is_array( $stored ) && ( $stored['signature'] ?? '' ) === $signature && is_array( $stored['abilities'] ?? null ) ) {
            self::$index = $stored['abilities'];
            return self::$index;
        }
        self::$index = self::rebuild_index( $signature );
        return self::$index;
    }

    /** `built` timestamp of the stored index and whether this request rebuilt it (Doctor evidence). */
    public static function index_meta(): array {
        $stored = get_option( self::OPTION_INDEX );
        return [
            'built'       => is_array( $stored ) ? (int) ( $stored['built'] ?? 0 ) : null,
            'rebuilt_now' => self::$rebuilt_now,
        ];
    }

    /** Counts + evidence for the Connection Doctor. Instantiates the registry (which registers the bridge). */
    public static function doctor_stats(): array {
        $registered = $withheld = $inbound = 0;
        if ( function_exists( 'wp_get_abilities' ) ) {
            wp_get_abilities();                               // fires wp_abilities_api_init -> register_outbound()
            $registered = count( self::$registered );
            $withheld   = max( 0, count( self::index() ) - $registered );
        }
        if ( ! empty( Cowboy_MCP_Tools::get_settings()['abilities_consume'] ?? true ) ) {
            $inbound = (int) ( Cowboy_MCP_Tools::get_tool_catalog()['categories']['abilities']['count'] ?? 0 );
        }
        $meta = self::index_meta();
        return [
            'registered' => $registered,
            'withheld'   => $withheld,
            'inbound'    => $inbound,
            'evidence'   => [
                'wp-abilities REST: ' . ( class_exists( 'WP_REST_Abilities_V1_Run_Controller' ) ? 'yes' : 'no' ),
                'mcp-adapter: ' . ( class_exists( 'WP\MCP\Core\McpAdapter' ) ? 'loaded (bundled by an active plugin or installed)' : 'not loaded' ),
                'index: ' . ( $meta['built'] ? 'built ' . gmdate( 'Y-m-d H:i', $meta['built'] ) . ' UTC' : 'not stored' ) . ( $meta['rebuilt_now'] ? ', rebuilt now' : ', signature ok' ),
            ],
        ];
    }

    /**
     * Build the definitions index from the live domain files (~35 ms) and
     * persist it. Returns the abilities map even when persisting fails, so a
     * broken option write costs one rebuild per request, not one per registry
     * init. Called on signature mismatch, on settings save, and by the Doctor.
     */
    public static function rebuild_index( ?string $signature = null ): array {
        $signature ??= Cowboy_MCP_Tools::domain_signature();
        try {
            $defs = Cowboy_MCP_Tools::get_bridge_definitions();
        } catch ( \Throwable $e ) {
            Cowboy_MCP_Auth::log( 'ability_index_failed', [ 'error' => $e->getMessage() ] );
            return [];
        }

        $abilities = [];
        foreach ( $defs['tools'] as $tool ) {
            $name = (string) ( $tool['name'] ?? '' );
            if ( $name === '' || in_array( $name, self::NEVER, true ) ) {
                continue;
            }
            $ann         = (array) ( $tool['annotations'] ?? [] );
            $readonly    = ! empty( $ann['readOnlyHint'] );
            $destructive = ! empty( $ann['destructiveHint'] );
            $idempotent  = ! empty( $ann['idempotentHint'] );

            $schema = (array) ( $tool['inputSchema'] ?? [] );
            if ( ! isset( $schema['properties'] ) || $schema['properties'] instanceof \stdClass ) {
                $schema['properties'] = [];
            }
            $schema['type']    = 'object';
            $schema['default'] = [];   // stored as a plain array; register_outbound() casts to (object) at registration

            $undoable    = ! $readonly && Cowboy_MCP_Rollback::is_undoable_tool( $name );
            $description = (string) ( $tool['description'] ?? '' );
            if ( ! $readonly ) {
                // The undo sentence is a promise; only make it where it holds.
                $description .= $undoable
                    ? ' Mutating call: pass dry_run:true to preview; destructive tools need confirm:true while safe mode is on; the change is journaled and can be reverted with cowboy-mcp/wp-undo-change.'
                    : ' Mutating call: pass dry_run:true to preview; destructive tools need confirm:true while safe mode is on. This tool is not undoable (see meta.cowboy_mcp.undoable).';
            }

            $abilities[ self::NAMESPACE . '/' . str_replace( '_', '-', $name ) ] = [
                'tool'         => $name,
                'label'        => self::label( $name ),
                'description'  => $description,
                'input_schema' => $schema,
                'annotations'  => [ 'readOnlyHint' => $readonly, 'destructiveHint' => $destructive, 'idempotentHint' => $idempotent ],
                'category'     => (string) ( $defs['categories'][ $name ] ?? 'system' ),
                'undoable'     => $undoable,
                // Mirrors core's REST verb rule (readonly -> GET, destructive AND idempotent -> DELETE, else POST).
                'http_method'  => $readonly ? 'GET' : ( ( $destructive && $idempotent ) ? 'DELETE' : 'POST' ),
            ];
        }

        $written = update_option( self::OPTION_INDEX, [
            'signature' => $signature,
            'version'   => COWBOY_MCP_VERSION,
            'built'     => time(),
            'abilities' => $abilities,
        ], false );
        self::$rebuilt_now = true;

        if ( ! $written && ! self::$persist_fail_logged ) {
            // update_option() also returns false when the value is unchanged — only
            // report a real failure (stored copy missing or carrying another signature).
            $check = get_option( self::OPTION_INDEX );
            if ( ! is_array( $check ) || ( $check['signature'] ?? '' ) !== $signature ) {
                self::$persist_fail_logged = true;
                Cowboy_MCP_Auth::log( 'ability_index_persist_failed', [ 'signature' => $signature ] );
            }
        }
        return $abilities;
    }

    /** Humanised label: prefix -> product name, underscores -> spaces. */
    private static function label( string $tool ): string {
        $prefixes = [
            'wp_woo_'       => 'WooCommerce: ',
            'wp_acf_'       => 'ACF: ',
            'wp_seo_'       => 'SEO: ',
            'wp_wordfence_' => 'Wordfence: ',
            'wp_elementor_' => 'Elementor: ',
            'wp_cache_'     => 'Cache: ',
            'wp_forms_'     => 'Forms: ',
            'cowboy_mcp_'   => 'Cowboy: ',
            'wp_'           => '',
        ];
        foreach ( $prefixes as $prefix => $head ) {
            if ( str_starts_with( $tool, $prefix ) ) {
                return $head . ucfirst( str_replace( '_', ' ', substr( $tool, strlen( $prefix ) ) ) );
            }
        }
        return ucfirst( str_replace( '_', ' ', $tool ) );
    }

    /* ── Execution shim ───────────────────────────────────── */

    /**
     * execute_callback for every cowboy-mcp/* ability. Dispatches through
     * call_tool() with attribution, a per-user rate limit on foreign transports,
     * boolean normalisation and a recursion cap; maps the MCP response to
     * plain data / WP_Error (spec §6.6).
     */
    public static function execute_tool( string $tool, $input = null ) {
        if ( self::$transport_depth >= 2 ) {
            return new WP_Error( 'cowboy_mcp_recursion', 'Nested ability execution is capped at two levels.', [ 'status' => 409 ] );
        }

        $args = is_array( $input ) ? $input : (array) $input;   // stdClass from the schema default / a {} body
        // Coerce every value to the registered schema (core validated it already). On 6.9/7.0 the
        // run controller passes GET/DELETE query input through as strings, and handlers test
        // booleans with empty()/(bool) — "false" must not read as true (e.g. wp_delete_post force).
        $schema  = self::$by_tool[ $tool ]['input_schema'] ?? [ 'type' => 'object' ];
        $coerced = rest_sanitize_value_from_schema( $args, $schema, 'input' );
        if ( is_array( $coerced ) ) {
            $args = $coerced;
        } elseif ( $coerced instanceof \stdClass ) {
            $args = (array) $coerced;
        }
        foreach ( [ 'dry_run', 'confirm' ] as $flag ) {
            if ( array_key_exists( $flag, $args ) ) {
                $args[ $flag ] = rest_sanitize_boolean( $args[ $flag ] );   // belt and braces on the two gate keys
            }
        }

        // Attribution: never replace a live Cowboy credential context — its scope
        // and label must survive a nested ability call. Only foreign transports
        // (REST run, WP-CLI, adapters) get the synthetic context + rate limit.
        $previous = Cowboy_MCP_Auth::$current_key_context;
        $foreign  = empty( $previous );
        if ( $foreign ) {
            $user = wp_get_current_user();
            Cowboy_MCP_Auth::$current_key_context = [
                'key_id'     => 'ability',
                'key_label'  => 'Abilities API · ' . ( $user->user_login !== '' ? $user->user_login : 'user' ) . ' (#' . (int) $user->ID . ')',
                'key_prefix' => '',
                'scope'      => null,
            ];
            $limit = (int) ( Cowboy_MCP_Tools::get_settings()['rate_limit'] ?? 120 );
            if ( ! Cowboy_MCP_Auth::check_rate_limit( 'ability_u' . (int) $user->ID, $limit ) ) {
                Cowboy_MCP_Auth::log( 'rate_limit_exceeded', Cowboy_MCP_Auth::$current_key_context );
                Cowboy_MCP_Auth::$current_key_context = $previous;
                return new WP_Error( 'mcp_rate_limit', 'Rate limit exceeded.', [ 'status' => 429 ] );
            }
        }

        ++self::$transport_depth;
        try {
            $result = Cowboy_MCP_Tools::call_tool( [ 'name' => $tool, 'arguments' => $args ] );
        } finally {
            --self::$transport_depth;
            Cowboy_MCP_Auth::$current_key_context = $previous;
        }
        return self::map_result( $result );
    }

    /** MCP tool response -> ability result (data) or WP_Error with an HTTP status. */
    private static function map_result( $result ) {
        if ( is_wp_error( $result ) ) {
            $data = (array) $result->get_error_data();
            if ( ! isset( $data['status'] ) ) {
                $data['status'] = match ( $result->get_error_code() ) {
                    'tool_blocked', 'tool_disabled', 'tool_scope_denied', 'ability_policy_denied' => 403,
                    'unknown_tool', 'invalid_params'                                            => 400,
                    default                                                                     => 500,
                };
                $result->add_data( $data );
            }
            return $result;
        }

        $text = (string) ( $result['content'][0]['text'] ?? '' );
        if ( ! empty( $result['isError'] ) ) {
            $decoded = json_decode( $text, true );
            if ( is_array( $decoded ) && ! empty( $decoded['confirmation_required'] ) ) {
                return new WP_Error( 'cowboy_mcp_confirmation_required', (string) ( $decoded['message'] ?? 'Confirmation required.' ), [
                    'status'  => 409,
                    'tool'    => $decoded['tool'] ?? null,
                    'preview' => $decoded['preview'] ?? null,
                    'hint'    => 'Resend with "confirm": true (GET/DELETE: input[confirm]=true in the query string).',
                ] );
            }
            return new WP_Error( 'cowboy_mcp_tool_error', $text !== '' ? $text : 'Tool error.', [ 'status' => 400 ] );
        }

        if ( array_key_exists( 'structuredContent', $result ) ) {
            return $result['structuredContent'];
        }
        $decoded = json_decode( $text, true );
        return is_array( $decoded ) ? $decoded : [ 'text' => $text ];
    }
}
