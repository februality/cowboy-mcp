<?php
/**
 * Cowboy MCP – Completions
 *
 * Provides auto-completion for prompt arguments and resource template parameters.
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Completion {

    /** Maximum completion values to return per request. */
    private const MAX_RESULTS = 100;

    /**
     * Handle completion/complete requests.
     */
    public static function complete( array $params ): array|WP_Error {
        $ref      = $params['ref'] ?? [];
        $argument = $params['argument'] ?? [];
        $ref_type = $ref['type'] ?? '';

        return match ( $ref_type ) {
            'ref/prompt'   => self::complete_prompt( $ref, $argument ),
            'ref/resource' => self::complete_resource( $ref, $argument ),
            default        => new \WP_Error( 'invalid_ref', "Unknown ref type: {$ref_type}", [ 'code' => -32602 ] ),
        };
    }

    /* ── Prompt completions ──────────────────────────────────── */

    private static function complete_prompt( array $ref, array $argument ): array {
        $prompt_name = $ref['name'] ?? '';
        $arg_name    = $argument['name'] ?? '';
        $prefix      = $argument['value'] ?? '';

        // Dynamic special-case: bulk-content-update/post_type returns live post types.
        if ( $prompt_name === 'bulk-content-update' && $arg_name === 'post_type' ) {
            return self::filter_and_format( self::get_post_type_names(), $prefix );
        }

        // All other prompts: read completions from the canonical prompt definitions.
        $values = Cowboy_MCP_Prompts::get_argument_completions( $prompt_name, $arg_name );

        return self::filter_and_format( $values, $prefix );
    }

    /* ── Resource template completions ───────────────────────── */

    private static function complete_resource( array $ref, array $argument ): array {
        $uri_template = $ref['uri'] ?? '';
        $arg_name     = $argument['name'] ?? '';
        $prefix       = $argument['value'] ?? '';

        return match ( $uri_template ) {
            'wordpress://posts/{id}'    => self::filter_and_format( self::get_recent_post_ids( $prefix ), $prefix ),
            'wordpress://options/{name}'=> self::filter_and_format( self::get_option_names( $prefix ), $prefix ),
            'wordpress://plugins/{slug}'=> self::filter_and_format( self::get_plugin_slugs(), $prefix ),
            'wordpress://users/{id}'    => self::filter_and_format( self::get_user_ids( $prefix ), $prefix ),
            default                     => self::filter_and_format( [], $prefix ),
        };
    }

    /* ── Data providers ──────────────────────────────────────── */

    private static function get_post_type_names(): array {
        return array_keys( get_post_types( [ 'public' => true ] ) );
    }

    private static function get_option_names( string $prefix ): array {
        global $wpdb;
        $like = $wpdb->esc_like( $prefix ) . '%';
        $names = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE %s
               AND option_name NOT LIKE %s
             ORDER BY option_name
             LIMIT 100",
            $like,
            '_transient%'
        ) ) ?: [];
        // Don't advertise secret option names as readable.
        return array_values( array_filter(
            $names,
            fn( string $name ) => ! Cowboy_MCP_Security::is_sensitive_option( $name )
        ) );
    }

    private static function get_plugin_slugs(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        // Return plugin file paths with / replaced by -- for URI safety
        return array_map(
            fn( string $file ) => str_replace( '/', '--', $file ),
            array_keys( get_plugins() )
        );
    }

    private static function get_recent_post_ids( string $prefix ): array {
        global $wpdb;
        $like = $wpdb->esc_like( $prefix ) . '%';
        return $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status != 'auto-draft'
               AND CAST(ID AS CHAR) LIKE %s
             ORDER BY post_date DESC
             LIMIT 100",
            $like
        ) ) ?: [];
    }

    private static function get_user_ids( string $prefix ): array {
        global $wpdb;
        $like = $wpdb->esc_like( $prefix ) . '%';
        return $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT ID FROM {$wpdb->users}
             WHERE CAST(ID AS CHAR) LIKE %s
             ORDER BY ID
             LIMIT 100",
            $like
        ) ) ?: [];
    }

    /* ── Filter + format ─────────────────────────────────────── */

    private static function filter_and_format( array $values, string $prefix ): array {
        if ( $prefix !== '' ) {
            $values = array_values( array_filter(
                $values,
                fn( $v ) => str_starts_with( (string) $v, $prefix )
            ) );
        }

        $total   = count( $values );
        $values  = array_slice( $values, 0, self::MAX_RESULTS );
        $has_more = $total > self::MAX_RESULTS;

        $result = [ 'values' => array_map( 'strval', $values ) ];
        if ( $has_more ) {
            $result['total']   = $total;
            $result['hasMore'] = true;
        }

        return [ 'completion' => $result ];
    }
}
