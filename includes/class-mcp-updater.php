<?php
/**
 * Cowboy MCP – Self-hosted plugin updater.
 *
 * Plugs into WordPress's native update system to offer one-click updates for the
 * plugin distributed outside WordPress.org. Checks a self-hosted JSON manifest
 * (COWBOY_MCP_UPDATE_URL), compares versions, and serves the GitHub release zip.
 * Fails closed: any fetch/parse/validation error simply yields "no update".
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Updater {

    /** Plugin slug (directory name and plugins_api slug). */
    private const SLUG = 'cowboy-mcp';

    /** Transient caching the validated manifest. */
    private const CACHE_KEY = 'cowboy_mcp_updater_manifest';

    /** Allowed host for the manifest's download_url (GitHub release downloads). */
    private const ALLOWED_PACKAGE_HOST = 'github.com';

    public static function init(): void {
        add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'check_for_update' ] );
        add_filter( 'plugins_api', [ __CLASS__, 'plugin_info' ], 10, 3 );
        add_action( 'upgrader_process_complete', [ __CLASS__, 'flush_cache_after_update' ], 10, 2 );
    }

    /**
     * Inject our update into the update_plugins site transient.
     *
     * @param mixed $transient
     * @return mixed
     */
    public static function check_for_update( $transient ) {
        if ( ! is_object( $transient ) || ! isset( $transient->checked ) ) {
            return $transient;
        }

        $force    = is_admin() && ! empty( $_GET['force-check'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $manifest = self::get_manifest( $force );
        if ( ! $manifest ) {
            return $transient;
        }

        $item = (object) [
            'id'           => COWBOY_MCP_BASENAME,
            'slug'         => self::SLUG,
            'plugin'       => COWBOY_MCP_BASENAME,
            'new_version'  => $manifest['version'],
            'url'          => $manifest['homepage'],
            'package'      => $manifest['download_url'],
            'tested'       => $manifest['tested'],
            'requires'     => $manifest['requires'],
            'requires_php' => $manifest['requires_php'],
        ];

        if ( version_compare( $manifest['version'], COWBOY_MCP_VERSION, '>' ) ) {
            $transient->response[ COWBOY_MCP_BASENAME ] = $item;
        } else {
            // Up-to-date: list under no_update so the auto-update toggle appears.
            $item->new_version = COWBOY_MCP_VERSION;
            $transient->no_update[ COWBOY_MCP_BASENAME ] = $item;
        }

        return $transient;
    }

    /**
     * Provide "View details" modal data via plugins_api.
     *
     * @param mixed  $result
     * @param string $action
     * @param object $args
     * @return mixed
     */
    public static function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
            return $result;
        }

        $manifest = self::get_manifest();
        if ( ! $manifest ) {
            return $result;
        }

        return (object) [
            'name'          => 'Cowboy MCP',
            'slug'          => self::SLUG,
            'version'       => $manifest['version'],
            'author'        => '<a href="https://cowboymcp.com">februality</a>',
            'homepage'      => $manifest['homepage'],
            'requires'      => $manifest['requires'],
            'tested'        => $manifest['tested'],
            'requires_php'  => $manifest['requires_php'],
            'last_updated'  => $manifest['last_updated'],
            'sections'      => array_map( static fn( $v ) => wp_kses_post( (string) $v ), (array) $manifest['sections'] ),
            'download_link' => $manifest['download_url'],
        ];
    }

    /**
     * Clear the manifest cache after our plugin is updated.
     *
     * @param mixed $upgrader
     * @param array $hook_extra
     */
    public static function flush_cache_after_update( $upgrader, $hook_extra ): void {
        if ( ! is_array( $hook_extra )
            || 'update' !== ( $hook_extra['action'] ?? '' )
            || 'plugin' !== ( $hook_extra['type'] ?? '' ) ) {
            return;
        }
        if ( in_array( COWBOY_MCP_BASENAME, (array) ( $hook_extra['plugins'] ?? [] ), true ) ) {
            delete_transient( self::CACHE_KEY );
        }
    }

    /**
     * Get the validated manifest, cached ~12h. Returns null on any failure.
     */
    public static function get_manifest( bool $force = false ): ?array {
        if ( ! $force ) {
            $cached = get_transient( self::CACHE_KEY );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        $manifest = self::fetch_manifest();
        if ( $manifest ) {
            set_transient( self::CACHE_KEY, $manifest, 12 * HOUR_IN_SECONDS );
        }
        return $manifest;
    }

    /**
     * Fetch + decode + validate the manifest from COWBOY_MCP_UPDATE_URL. Null on failure.
     */
    private static function fetch_manifest(): ?array {
        $response = wp_remote_get( COWBOY_MCP_UPDATE_URL, [
            'timeout'    => 10,
            'sslverify'  => true,
            'user-agent' => 'CowboyMCP/' . COWBOY_MCP_VERSION . '; ' . home_url( '/' ),
        ] );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return self::validate_manifest( $data );
    }

    /**
     * Validate + normalize a decoded manifest. Returns the sanitized array or null.
     * Pure (no side effects / no WP I/O) so it can be unit-tested in isolation.
     *
     * @param mixed $data
     */
    public static function validate_manifest( $data ): ?array {
        if ( ! is_array( $data ) ) {
            return null;
        }
        $version = isset( $data['version'] ) ? trim( (string) $data['version'] ) : '';
        $url     = isset( $data['download_url'] ) ? trim( (string) $data['download_url'] ) : '';
        if ( '' === $version || '' === $url ) {
            return null;
        }
        // download_url must be HTTPS on the allowed host (defense-in-depth: a tampered
        // manifest can't point the package at an arbitrary host). Hostnames are
        // case-insensitive, so normalize before the exact-match check.
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || self::ALLOWED_PACKAGE_HOST !== $host ) {
            return null;
        }

        return [
            'version'      => $version,
            'download_url' => $url,
            'homepage'     => isset( $data['homepage'] ) ? (string) $data['homepage'] : 'https://cowboymcp.com',
            'requires'     => isset( $data['requires'] ) ? (string) $data['requires'] : '',
            'tested'       => isset( $data['tested'] ) ? (string) $data['tested'] : '',
            'requires_php' => isset( $data['requires_php'] ) ? (string) $data['requires_php'] : '',
            'last_updated' => isset( $data['last_updated'] ) ? (string) $data['last_updated'] : '',
            'sections'     => isset( $data['sections'] ) && is_array( $data['sections'] ) ? $data['sections'] : [],
        ];
    }
}
